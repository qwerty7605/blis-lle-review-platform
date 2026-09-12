import { computed, onMounted, ref } from 'vue'
import { studentAnalyticsApi } from '@/api/studentAnalytics.api'
import { LIBRARY_SUBJECT_CATEGORIES } from '@/constants/librarySubjects'
import { formatDateTime } from './useDashboardFormatters'

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

function humanizeFeatureKey(feature) {
  return String(feature ?? '')
    .split('_')
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
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
  const studyPlanRows = ref([])
  const weakAreaRaw = ref(null)
  const predictiveModel = ref({
    status: 'awaiting_ml_api',
    title: 'Predictive Readiness Model',
    description: 'Prepared for future J48 (C4.5) and logistic regression API integration.',
    ml_api_endpoint: null,
    expected_inputs: [],
    probability_percent: null,
    risk_band: null,
    decision_rules: [],
  })

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

    return {
      basis: raw.basis,
      subject: raw.subject ?? null,
      subjectScore: raw.subject_score === null || raw.subject_score === undefined ? null : Number(raw.subject_score),
      topic: raw.topic ?? null,
      topicScore: raw.topic_score === null || raw.topic_score === undefined ? null : Number(raw.topic_score),
      subtopic: raw.subtopic ?? null,
      subtopicScore: raw.subtopic_score === null || raw.subtopic_score === undefined ? null : Number(raw.subtopic_score),
    }
  })

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
      const score = roundPercent(isSubtopic ? area.subtopicScore : area.topicScore)

      headline = {
        key: 'opportunity',
        label: 'Biggest opportunity',
        text: `${place}${score !== null ? ` — you're only scoring ${score}% there` : ''}. Start your next study session here.`,
      }
    } else if (area && area.basis === 'subject') {
      const score = roundPercent(area.subjectScore)
      headline = {
        key: 'opportunity',
        label: 'Biggest opportunity',
        text: `${area.subject}${score !== null ? ` — averaging ${score}% so far` : ''}. Start your next study session here.`,
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

  const predictivePanel = computed(() => {
    const status = predictiveModel.value.status

    const statusLabel = status === 'connected'
      ? 'Connected'
      : status === 'insufficient_history'
        ? 'Needs More Data'
        : 'Coming Soon'

    return {
      ...predictiveModel.value,
      status,
      statusLabel,
      isConnected: status === 'connected',
      isInsufficientHistory: status === 'insufficient_history',
      featureLabels: asList(predictiveModel.value.expected_inputs).map(humanizeFeatureKey),
      predictionTone: predictiveModel.value.prediction === 'PASS' ? 'success' : 'danger',
    }
  })

  const hasAttemptData = computed(() => (stats.value.completed_attempts ?? 0) > 0)

  async function loadAnalytics() {
    analyticsLoading.value = true
    analyticsError.value = ''

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
      studyPlanRows.value = asList(data?.study_plan)
      weakAreaRaw.value = data?.weak_area ?? null
      predictiveModel.value = {
        ...predictiveModel.value,
        ...(data?.predictive_model ?? {}),
      }
    } catch (error) {
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
      studyPlanRows.value = []
      weakAreaRaw.value = null
    } finally {
      analyticsLoading.value = false
      analyticsLoaded.value = true
    }
  }

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
    recentAttempts,
    studyPlan,
    weakArea,
    reviewNarrative,
    predictivePanel,
    loadAnalytics,
  }
}
