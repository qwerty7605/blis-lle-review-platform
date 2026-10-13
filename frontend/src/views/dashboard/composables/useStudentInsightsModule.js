import { computed, onMounted, ref } from 'vue'
import { studentAnalyticsApi } from '@/api/studentAnalytics.api'
import { LIBRARY_SUBJECT_CATEGORIES } from '@/constants/librarySubjects'
import { formatDateTime } from './useDashboardFormatters'
import { useLiveRefresh } from '@/utils/liveRefresh'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function asList(value) {
  return Array.isArray(value) ? value : []
}

function scoreTone(score) {
  const numericScore = Number(score ?? 0)
  if (numericScore >= 85) return 'success'
  if (numericScore >= 75) return 'navy'
  if (numericScore >= 60) return 'gold'
  return 'danger'
}

function trendTone(trend) {
  if (trend === 'up') return 'success'
  if (trend === 'down') return 'danger'
  if (trend === 'steady') return 'navy'
  return 'neutral'
}

function bandLabel(band) {
  if (band === 'strong') return 'Doing Well'
  if (band === 'developing') return 'Almost There'
  if (band === 'focus') return 'Review More'
  return 'No Data'
}

export function useStudentInsightsModule() {
  const analyticsLoading = ref(false)
  const analyticsLoaded = ref(false)
  const analyticsError = ref('')
  const stats = ref({
    completed_attempts: 0,
    average_score_percent: null,
    latest_score_percent: null,
    latest_score_delta: null,
    focus_subjects_count: 0,
    passing_threshold: 75,
  })
  const subjectRows = ref([])
  const historyRows = ref([])
  const recentAttemptRows = ref([])
  // Every sitting, retakes included, newest first (Exam History).
  const examHistory = ref([])
  const studyPlanRows = ref([])
  const actionPlanRows = ref([])
  const weakAreaRaw = ref(null)
  // The board exam model's answer for this student, as the server words it
  // (BoardExamOutlook.php); null until the first load.
  const predictiveModel = ref(null)

  const subjects = computed(() => {
    const incoming = new Map(
      asList(subjectRows.value).map((subject) => [subject.label, subject]),
    )

    return LIBRARY_SUBJECT_CATEGORIES.map((label) => {
      const subject = incoming.get(label) ?? {
        label,
        attempts_count: 0,
        average_score_percent: null,
        latest_score_percent: null,
        best_score_percent: null,
        trend: 'none',
        trend_delta: null,
        performance_band: 'not_started',
      }

      return {
        ...subject,
        scoreTone: scoreTone(subject.average_score_percent ?? subject.latest_score_percent ?? 0),
        trendTone: trendTone(subject.trend),
        bandLabel: bandLabel(subject.performance_band),
        coveragePercent: Math.max(0, Math.min(100, Number(subject.average_score_percent ?? 0))),
      }
    })
  })

  const scoreHistory = computed(() => (
    asList(historyRows.value).map((attempt) => ({
      ...attempt,
      score: Number(attempt.score_percent ?? 0),
      scoreTone: scoreTone(attempt.score_percent),
      submittedAtText: attempt.submitted_at ? formatDateTime(attempt.submitted_at) : 'n/a',
    }))
  ))

  const recentAttempts = computed(() => (
    asList(recentAttemptRows.value).map((attempt) => ({
      ...attempt,
      scoreDisplay: attempt.score_percent === null ? 'N/A' : `${Number(attempt.score_percent).toFixed(1)}%`,
      scoreTone: scoreTone(attempt.score_percent),
      submittedAtText: attempt.submitted_at ? formatDateTime(attempt.submitted_at) : 'n/a',
    }))
  ))

  // Ranked weakest-first study queue (see
  // DashboardAnalyticsService::buildStudyPlan). reviewQuery is the deep link
  // the Review Bot's own picker already understands -- see
  // useReviewBotModule's onMounted, which preselects subject/topic/subtopic
  // straight off the query string.
  const studyPlan = computed(() => (
    asList(studyPlanRows.value).map((row, index) => ({
      ...row,
      rank: index + 1,
      score: row.score === null || row.score === undefined ? null : Number(row.score),
      scoreTone: scoreTone(row.score),
      reviewQuery: {
        subject: row.subject,
        ...(row.topic_id ? { topic_id: String(row.topic_id) } : {}),
        ...(row.subtopic_id ? { subtopic_id: String(row.subtopic_id) } : {}),
      },
    }))
  ))

  /*
   * What to actually do next, worst-first (see StudyActionPlanner). Distinct
   * from studyPlan above: that ranks *areas*, this names *moves* -- each one
   * already sized to the practice that exists behind it, so a row either
   * carries a link that works or openly says nothing is published yet.
   */
  const actionPlan = computed(() => (
    asList(actionPlanRows.value).map((row, index) => ({
      ...row,
      rank: index + 1,
      hasCta: Boolean(row.review_query && row.cta_label),
    }))
  ))

  const averageScore = computed(() => (
    stats.value.average_score_percent === null ? null : Number(stats.value.average_score_percent)
  ))

  const strongestSubject = computed(() => (
    subjects.value
      .filter((subject) => subject.attempts_count > 0 && subject.average_score_percent !== null)
      .sort((left, right) => Number(right.average_score_percent ?? 0) - Number(left.average_score_percent ?? 0))[0]
      ?? null
  ))

  const focusSubjects = computed(() => (
    subjects.value
      .filter((subject) => subject.attempts_count > 0 && subject.performance_band === 'focus')
      .sort((left, right) => Number(left.average_score_percent ?? 0) - Number(right.average_score_percent ?? 0))
  ))

  const strengths = computed(() => (
    subjects.value
      .filter((subject) => subject.attempts_count > 0 && subject.performance_band === 'strong')
      .sort((left, right) => Number(right.average_score_percent ?? 0) - Number(left.average_score_percent ?? 0))
      .slice(0, 3)
  ))

  // Latest Result and Review Areas used to also live here as stat cards --
  // removed on request (redundant with the Recent Scores trend chart and the
  // Review Areas count/trend now folded into the reviewNarrative paragraph
  // below), just no longer duplicated as its own tile.
  const statCards = computed(() => ([
    {
      label: 'Exams Taken',
      value: String(stats.value.completed_attempts ?? 0),
      hint: 'Finished exams',
      tone: 'navy',
    },
    {
      label: 'Overall Average',
      value: averageScore.value === null ? 'N/A' : `${averageScore.value.toFixed(1)}%`,
      hint: averageScore.value === null
        ? 'Take one exam to see this'
        : 'Based on finished exams',
      tone: 'success',
    },
  ]))

  // Normalized view of the backend's subject -> topic -> subtopic weak-area
  // resolution (DashboardAnalyticsService::buildWeakAreaRecommendation, the
  // same one "Review Your Weak Areas" already uses) -- whichever level has
  // real data wins: a weak subtopic beats its topic, which beats its subject.
  const weakArea = computed(() => {
    const raw = weakAreaRaw.value
    if (!raw || !raw.basis) return null

    // mastery is the Bayesian Knowledge Tracing estimate for this area (see
    // KnowledgeTracingService): the probability the student actually knows it,
    // read off the order their answers arrived in rather than the raw
    // percentage. Null for an area the tracer had no sequence for.
    const mastery = raw.mastery ?? null

    return {
      basis: raw.basis,
      subject: raw.subject ?? null,
      subjectScore: raw.subject_score === null || raw.subject_score === undefined ? null : Number(raw.subject_score),
      topic: raw.topic ?? null,
      topicScore: raw.topic_score === null || raw.topic_score === undefined ? null : Number(raw.topic_score),
      subtopic: raw.subtopic ?? null,
      subtopicScore: raw.subtopic_score === null || raw.subtopic_score === undefined ? null : Number(raw.subtopic_score),
      attemptedCount: Number(raw.attempted_count ?? 0),
      correctCount: Number(raw.correct_count ?? 0),
      masteryPercent: mastery?.mastery_percent === undefined ? null : Number(mastery.mastery_percent),
      trend: mastery?.trend ?? null,
      band: mastery?.band ?? null,
      // Same deep link the study plan and "Review Your Weak Areas" already use.
      reviewQuery: {
        subject: raw.subject,
        ...(raw.topic_id ? { topic_id: String(raw.topic_id) } : {}),
        ...(raw.subtopic_id ? { subtopic_id: String(raw.subtopic_id) } : {}),
      },
    }
  })

  /**
   * How the traced mastery reads in a sentence. Trend is the part a percentage
   * could never tell you -- two students on the same score can be moving in
   * opposite directions -- so it leads when the model is confident enough to
   * report one.
   */
  function masteryClause(area) {
    if (area.trend === 'improving') return ', and it is starting to click'
    if (area.trend === 'slipping') return ', and you were doing better on it earlier'
    if (area.trend === 'steady') return ', and it has not shifted yet'

    return ''
  }

  /**
   * The evidence the recommendation rests on, stated plainly. Showing the
   * denominator matters: "2 of 6 correct" is honest about how much the system
   * actually has to go on, where a bare "33%" hides whether that came from six
   * answers or one.
   */
  function evidenceText(area) {
    if (!area.attemptedCount) return 'your weakest mapped area so far'

    return `${area.correctCount} of ${area.attemptedCount} correct`
  }

  function masteryBadge(area) {
    if (area.masteryPercent === null) return null

    return {
      percent: Math.round(area.masteryPercent),
      band: area.band,
      trend: area.trend,
      attempted: area.attemptedCount,
    }
  }

  function roundPercent(value) {
    return value === null || value === undefined ? null : Math.round(Number(value))
  }

  // Structured in-depth read replacing the old title/description guidance
  // cards -- a bolded headline naming the actual weakest subtopic or topic
  // when the student's items carry that mapping (not just the subject it
  // lives under), plus supporting bullet points. Kept as labeled parts
  // rather than one prose string so the template can style the lead-in,
  // labels, and body text differently without resorting to v-html.
  const reviewNarrative = computed(() => {
    if ((stats.value.completed_attempts ?? 0) === 0) {
      return {
        empty: true,
        emptyMessage: 'Take your first exam to unlock a personalized study plan based on your results.',
      }
    }

    const area = weakArea.value
    const focusCount = focusSubjects.value.length
    let headline = null

    if (area && (area.basis === 'subtopic' || area.basis === 'topic')) {
      const isSubtopic = area.basis === 'subtopic'
      const place = isSubtopic
        ? `"${area.subtopic}" (under ${area.topic}, in ${area.subject})`
        : `"${area.topic}" in ${area.subject}`

      headline = {
        key: 'opportunity',
        label: 'Biggest opportunity',
        text: `${place} — ${evidenceText(area)}${masteryClause(area)}. Start your next study session here.`,
        mastery: masteryBadge(area),
        reviewQuery: area.reviewQuery,
      }
    } else if (area && area.basis === 'subject') {
      headline = {
        key: 'opportunity',
        label: 'Biggest opportunity',
        text: `${area.subject} — ${evidenceText(area)}${masteryClause(area)}. Start your next study session here.`,
        mastery: masteryBadge(area),
        reviewQuery: area.reviewQuery,
      }
    } else if (focusCount === 0) {
      headline = {
        key: 'on_track',
        label: 'You are on track',
        text: 'Every subject you have attempted so far is already at 75% or higher.',
      }
    }

    const points = []

    if (focusCount > 1) {
      const others = focusSubjects.value
        .filter((subject) => subject.label !== area?.subject)
        .slice(0, 2)
        .map((subject) => subject.label)

      if (others.length > 0) {
        points.push({
          key: 'also_review',
          label: 'Also review',
          text: `${others.join(' and ')} ${others.length === 1 ? 'is' : 'are'} still below the 75% passing mark.`,
        })
      }
    }

    const strongSubject = strengths.value[0] ?? strongestSubject.value
    if (strongSubject && strongSubject.label !== area?.subject) {
      const score = roundPercent(strongSubject.average_score_percent)
      const scoreText = score !== null ? ` at ${score}%` : ''

      // A merely-relatively-best subject that's still below passing shouldn't
      // be called "your strongest subject" outright -- that reads as praise
      // when the honest note is "still needs work, just less than the rest."
      points.push(
        strongSubject.performance_band === 'strong'
          ? { key: 'strength', label: 'Strongest subject', text: `${strongSubject.label}${scoreText}.` }
          : { key: 'strength', label: 'Relative strength', text: `${strongSubject.label}${scoreText} — your best so far, though still below passing.` },
      )
    }

    return { empty: false, headline, points }
  })

  const predictivePanel = computed(() => predictiveModel.value)

  const hasAttemptData = computed(() => (stats.value.completed_attempts ?? 0) > 0)

  // silent: the quiet refresh behind useLiveRefresh (below). A failure then
  // leaves the page as it was instead of emptying it.
  async function loadAnalytics({ silent = false } = {}) {
    if (!silent) {
      analyticsLoading.value = true
      analyticsError.value = ''
    }

    try {
      const { data } = await studentAnalyticsApi.summary()

      stats.value = {
        completed_attempts: Number(data?.stats?.completed_attempts ?? 0),
        average_score_percent: data?.stats?.average_score_percent === null
          ? null
          : Number(data?.stats?.average_score_percent),
        latest_score_percent: data?.stats?.latest_score_percent === null
          ? null
          : Number(data?.stats?.latest_score_percent),
        latest_score_delta: data?.stats?.latest_score_delta === null
          ? null
          : Number(data?.stats?.latest_score_delta),
        focus_subjects_count: Number(data?.stats?.focus_subjects_count ?? 0),
        passing_threshold: Number(data?.stats?.passing_threshold ?? 75),
      }
      subjectRows.value = asList(data?.subjects)
      historyRows.value = asList(data?.history)
      recentAttemptRows.value = asList(data?.recent_attempts)
      examHistory.value = asList(data?.exam_history)
      studyPlanRows.value = asList(data?.study_plan)
      actionPlanRows.value = asList(data?.action_plan)
      weakAreaRaw.value = data?.weak_area ?? null
      predictiveModel.value = {
        ...(data?.predictive_model ?? {}),
      }
    } catch (error) {
      if (silent) return

      analyticsError.value = firstApiError(error, 'Unable to load student analytics right now.')
      stats.value = {
        completed_attempts: 0,
        average_score_percent: null,
        latest_score_percent: null,
        latest_score_delta: null,
        focus_subjects_count: 0,
        passing_threshold: 75,
      }
      subjectRows.value = []
      historyRows.value = []
      recentAttemptRows.value = []
      examHistory.value = []
      studyPlanRows.value = []
      actionPlanRows.value = []
      weakAreaRaw.value = null
    } finally {
      if (!silent) analyticsLoading.value = false
      analyticsLoaded.value = true
    }
  }

  // Read again when the student comes back to this page, after an exam taken
  // in another tab, say.
  useLiveRefresh(() => loadAnalytics({ silent: true }), {
    when: () => analyticsLoaded.value && !analyticsLoading.value,
  })

  onMounted(() => {
    loadAnalytics()
  })

  return {
    analyticsLoading,
    analyticsLoaded,
    analyticsError,
    hasAttemptData,
    stats,
    statCards,
    subjects,
    averageScore,
    strongestSubject,
    focusSubjects,
    strengths,
    scoreHistory,
    examHistory,
    recentAttempts,
    studyPlan,
    actionPlan,
    weakArea,
    reviewNarrative,
    predictivePanel,
    loadAnalytics,
  }
}
