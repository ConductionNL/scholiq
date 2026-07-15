/**
 * Scholiq v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. CnPageRenderer
 * resolves each manifest page's `component` string against entries whose
 * `kind === "page"` (with precedence over the deprecated `customComponents`
 * prop, which Scholiq no longer ships).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import ItemAuthorView from './views/ItemAuthorView.vue'
import ItemAnalysisView from './views/ItemAnalysisView.vue'
import ExamCaseDossierView from './views/ExamCaseDossierView.vue'
import GradeImpactDetail from './views/GradeImpactDetail.vue'
import LessonPlayer from './views/LessonPlayer.vue'
import MarkSubmissionView from './views/MarkSubmissionView.vue'
import PeerReviewMarkingView from './views/PeerReviewMarkingView.vue'
import SelfAssessmentView from './views/SelfAssessmentView.vue'
import ProctoringReviewQueue from './views/ProctoringReviewQueue.vue'
import RegulationDetailPage from './views/RegulationDetailPage.vue'
import ScholiqSettings from './views/ScholiqSettings.vue'
import TakeAssessmentView from './views/TakeAssessmentView.vue'
import ScholiqDashboards from './views/ScholiqDashboards.vue'
// bsa-study-progress-guard / competency-framework: these two custom views
// existed but were never added to this registry (a pre-existing gap
// discovered while wiring learning-progress-and-analytics's own new custom
// view below — both routes were unreachable at HEAD; fixed here alongside).
import BsaRiskDashboard from './views/BsaRiskDashboard.vue'
import SkillsGapDashboard from './views/SkillsGapDashboard.vue'
// learning-progress-and-analytics: cohort x period grade-trend heat map —
// the one genuine new custom view this change adds.
import GroupTrendHeatmap from './views/GroupTrendHeatmap.vue'
// Per-role dashboard route wrappers (group-gated menu items; replaces the
// single role-switcher dashboard).
import DashboardAdmin from './views/DashboardAdmin.vue'
import DashboardTeacher from './views/DashboardTeacher.vue'
import DashboardStudent from './views/DashboardStudent.vue'
import ScholiqCompliance from './views/ScholiqCompliance.vue'
import ScholiqLearnerHome from './views/ScholiqLearnerHome.vue'
import RolloverWizard from './views/RolloverWizard.vue'
import AuditTrailWidget from './components/widgets/AuditTrailWidget.vue'
// nav-restructure-dashboards (supersedes ADR-044 cards-collapse): the Learning
// and People groups land on domain dashboards instead of tile-grid card pages.
import LearningDashboard from './views/LearningDashboard.vue'
import PeopleDashboard from './views/PeopleDashboard.vue'
// personal-timetable: the signed-in user's own week view over Session objects.
import MyTimetable from './views/MyTimetable.vue'
// parent-evening-planner: the guardian/self conversation-slot picker and the
// coordinator's manual-override / regenerate board.
import BookConferenceSlotsView from './views/BookConferenceSlotsView.vue'
import ConferenceScheduleBoard from './views/ConferenceScheduleBoard.vue'
// report-card-composer: the rapportvergadering cohort-wide review grid
// (mirrors GradebookView's "no manifest page can render a cohort grid"
// precedent) — the ComposeReportPeriodModal dialog it hosts is imported
// directly by the view itself, not registered here (a plain component, same
// as procest's src/dialogs/*.vue shape, not a routed page).
import RapportvergaderingReviewView from './views/RapportvergaderingReviewView.vue'
// eportfolio: the learner's evidence-picker portfolio builder and the
// teacher/praktijkopleider/external-assessor read-only review + grading
// surface — the two named custom views the eportfolio spec permits.
import PortfolioBuilder from './views/PortfolioBuilder.vue'
import PortfolioReviewView from './views/PortfolioReviewView.vue'
// pupil-dossier-notes: the one genuine new custom view this change adds —
// the chronological DossierNote/BehaviourIncident/WellbeingCheckIn +
// LearningPlan/SupportRequest/DeliberationRecord merge for one learner.
import PupilDossierTimelineView from './views/PupilDossierTimelineView.vue'
// engagement-gamification: the one genuine new custom view this change adds —
// the opt-in, opt-out-respecting leaderboard ranking (LeaderboardController's
// response); every other engagement object is a declarative manifest page.
import LeaderboardView from './views/LeaderboardView.vue'
// course-evaluation: the one genuine new custom view this change adds — a
// coordinator/opleidingscommissie view of a course's CourseQualityScore
// trend over time, response rate, and raw free-text answers.
import CourseQualityReport from './views/CourseQualityReport.vue'
// admissions-and-subject-choice: the two genuine new custom views this
// change adds — the coordinator's admissions review board (queue of
// intake-completed Applications cross-referenced against their round's
// deadline/kind/capacity) and the interactive vakkenpakket elective picker
// with live electiveRules/capacity feedback; every other Application/
// AdmissionsRound/SubjectChoice screen is a declarative manifest page.
import AdmissionsReviewBoard from './views/AdmissionsReviewBoard.vue'
import SubjectChoicePicker from './views/SubjectChoicePicker.vue'
// groepsplan: the one genuine new custom view this change adds — resolves
// each GroupPlanSubgroup member learner's active LearningPlan (if any); the
// manifest's equality-only filter DSL cannot express the learnerIds
// array-membership lookup. Every other GroupPlan/GroupPlanSubgroup/
// GroupPlanEvaluation screen is a declarative manifest page.
import GroupPlanSubgroupLearnerContext from './views/GroupPlanSubgroupLearnerContext.vue'

/**
 * Wrap a Vue component into the v2 registry shape required by CnAppRoot's
 * `registry` prop (`kind: "page"` is the discriminator CnPageRenderer keys
 * page dispatch off — `kind: "widget"`/`"modal"`/`"form-field"`/
 * `"cell-renderer"` entries with the same name are NOT used for page
 * dispatch).
 *
 * @param {object} component Vue component options.
 *
 * @return {object} A `{ kind: "page", component }` registry entry.
 */
function page(component) {
	return { kind: 'page', component }
}

/*
 * Grid metadata required for every kind:"widget" registry entry by the
 * ADR-036 registry validator in CnAppRoot. `allowedSlots` uses the v2 slot
 * literals; `audit-trail` is placed on both body and sidebar since detail
 * pages use it in either position depending on the page's layout.
 */
const PANEL_WIDGET_META = {
	defaultSize: { w: 6, h: 4 },
	minSize: { w: 3, h: 2 },
	maxSize: { w: 12, h: 6 },
	allowedSlots: ['body', 'sidebar'],
	propsSchema: null,
}

export default {
	AdmissionsReviewBoard: page(AdmissionsReviewBoard),
	BookConferenceSlotsView: page(BookConferenceSlotsView),
	BsaRiskDashboard: page(BsaRiskDashboard),
	ConferenceScheduleBoard: page(ConferenceScheduleBoard),
	CourseQualityReport: page(CourseQualityReport),
	DashboardAdmin: page(DashboardAdmin),
	DashboardTeacher: page(DashboardTeacher),
	DashboardStudent: page(DashboardStudent),
	ExamCaseDossierView: page(ExamCaseDossierView),
	GradeImpactDetail: page(GradeImpactDetail),
	GroupPlanSubgroupLearnerContext: page(GroupPlanSubgroupLearnerContext),
	GroupTrendHeatmap: page(GroupTrendHeatmap),
	ItemAnalysisView: page(ItemAnalysisView),
	ItemAuthorView: page(ItemAuthorView),
	LeaderboardView: page(LeaderboardView),
	LearningDashboard: page(LearningDashboard),
	LessonPlayer: page(LessonPlayer),
	MarkSubmissionView: page(MarkSubmissionView),
	MyTimetable: page(MyTimetable),
	PeerReviewMarkingView: page(PeerReviewMarkingView),
	PeopleDashboard: page(PeopleDashboard),
	PortfolioBuilder: page(PortfolioBuilder),
	PortfolioReviewView: page(PortfolioReviewView),
	ProctoringReviewQueue: page(ProctoringReviewQueue),
	PupilDossierTimelineView: page(PupilDossierTimelineView),
	RapportvergaderingReviewView: page(RapportvergaderingReviewView),
	RegulationDetailPage: page(RegulationDetailPage),
	RolloverWizard: page(RolloverWizard),
	ScholiqCompliance: page(ScholiqCompliance),
	ScholiqDashboards: page(ScholiqDashboards),
	ScholiqLearnerHome: page(ScholiqLearnerHome),
	ScholiqSettings: page(ScholiqSettings),
	SelfAssessmentView: page(SelfAssessmentView),
	SkillsGapDashboard: page(SkillsGapDashboard),
	SubjectChoicePicker: page(SubjectChoicePicker),
	TakeAssessmentView: page(TakeAssessmentView),

	// --- Shared library widgets registered under manifest widget keys (ADR-036). ---
	'audit-trail': {
		kind: 'widget',
		component: AuditTrailWidget,
		...PANEL_WIDGET_META,
		_note: 'Object change-log card — self-fetches from the detail object context (register/schema/objectId).',
	},
}
