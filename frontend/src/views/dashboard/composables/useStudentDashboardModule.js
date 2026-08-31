import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { studentDashboardApi } from '@/api/studentDashboard.api'
import { studentAnalyticsApi } from '@/api/studentAnalytics.api'
import { formatDateTime, parseDateTime } from './useDashboardFormatters'
import {
  canStudentOpenExam,
  examHasQuestionSet,
  examScheduleEnd,
  examScheduleStart,
  isStudentExamCompleted,
  isStudentExamEnded,
  isStudentExamInProgress,
  isStudentExamRetakeLimitReached,
  isStudentExamUpcoming,
  studentExamActionLabel,
  studentExamAvailabilityText,
} from './studentExamAvailability'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function asList(value) {
  return Array.isArray(value) ? value : []
}

function examNotificationState(exam) {
  if (!examHasQuestionSet(exam)) return 'unavailable'
  if (isStudentExamInProgress(exam)) return 'in_progress'
  if (isStudentExamUpcoming(exam)) return 'upcoming'
  if (isStudentExamEnded(exam)) return 'closed'
  if (isStudentExamCompleted(exam) && isStudentExamRetakeLimitReached(exam)) return 'review_only'
  if (isStudentExamCompleted(exam)) return 'retake'
  if (canStudentOpenExam(exam)) return 'available'
  return 'info'
}

function notificationPriority(state) {
  switch (state) {
    case 'in_progress':
      return 0
    case 'available':
      return 1
    case 'retake':
      return 2
    case 'upcoming':
      return 3
    case 'review_only':
      return 4
    case 'unavailable':
      return 5
    case 'closed':
      return 6
    default:
      return 7
  }
}

function notificationTone(state) {
  switch (state) {
    case 'in_progress':
      return 'navy'
    case 'available':
      return 'success'
    case 'retake':
      return 'gold'
    case 'upcoming':
      return 'neutral'
    case 'review_only':
      return 'success'
    case 'closed':
      return 'danger'
    default:
      return 'neutral'
  }
}

function notificationLabel(state) {
  switch (state) {
    case 'in_progress':
      return 'Resume ready'
    case 'available':
      return 'Available now'
    case 'retake':
      return 'Retake open'
    case 'upcoming':
      return 'Upcoming'
    case 'review_only':
      return 'Review only'
    case 'closed':
      return 'Window closed'
    case 'unavailable':
      return 'Needs setup'
    default:
      return 'Notice'
  }
}

function examTimeValue(exam) {
  const parsed = new Date(examScheduleStart(exam) ?? exam?.schedule_end_at ?? exam?.assigned_at ?? 0)
  return Number.isNaN(parsed.getTime()) ? Number.MAX_SAFE_INTEGER : parsed.getTime()
}

function examDueAtValue(exam) {
  const parsed = new Date(examScheduleEnd(exam) ?? 0)
  return Number.isNaN(parsed.getTime()) ? null : parsed.getTime()
}

function examAssignedAtValue(exam) {
  const parsed = new Date(exam?.assigned_at ?? 0)
  return Number.isNaN(parsed.getTime()) ? 0 : parsed.getTime()
}

// Notice ordering: closing soonest first, then open retakes, then the most
// recently assigned exams, then everything else — a due date always wins
// over category, since that's the most time-sensitive signal for a student.
function noticeTier(notice) {
  if (notice.dueAt !== null) return 0
  if (notice.state === 'retake') return 1
  if (notice.state === 'in_progress' || notice.state === 'available') return 2
  return 3
}

// Mirrors noticeTier so the "Due / Retake / New" filter tabs match exactly
// what "near due date" ordering already put at the top of the list.
function noticeCategory(tier) {
  if (tier === 0) return 'due'
  if (tier === 1) return 'retake'
  if (tier === 2) return 'new'
  return 'other'
}

function noticeCategoryLabel(category, state) {
  if (category === 'due') return 'Due'
  if (category === 'retake') return 'Retake'
  if (category === 'new') return 'New'
  return notificationLabel(state)
}

function formatShortDate(timestamp) {
  if (!timestamp) return 'n/a'

  const date = new Date(timestamp)
  if (Number.isNaN(date.getTime())) return 'n/a'

  const now = new Date()
  if (date.toDateString() === now.toDateString()) return 'Today'

  const tomorrow = new Date(now)
  tomorrow.setDate(tomorrow.getDate() + 1)
  if (date.toDateString() === tomorrow.toDateString()) return 'Tomorrow'

  return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(date)
}

// Every notice gets one meaningful date, whichever is most relevant to its
// state: a closing window, an opening date, or — lacking either — when it
// was assigned.
function noticeWhen(exam, dueAt) {
  if (dueAt !== null) {
    return { label: 'Due', text: formatShortDate(dueAt) }
  }

  const scheduleStart = parseDateTime(examScheduleStart(exam))
  if (scheduleStart && scheduleStart.getTime() > Date.now()) {
    return { label: 'Opens', text: formatShortDate(scheduleStart.getTime()) }
  }

  if (exam?.assigned_at) {
    return { label: 'Assigned', text: formatShortDate(examAssignedAtValue(exam)) }
  }

  return { label: '', text: 'n/a' }
}

function scoreTone(scorePercent) {
  const score = Number(scorePercent ?? 0)
  if (score >= 75) return 'success'
  if (score >= 50) return 'gold'
  return 'danger'
}

function lastExamSuggestion(scorePercent, subject) {
  const score = Number(scorePercent ?? 0)
  const subjectLabel = subject || 'this subject'

  if (score >= 75) {
    return "Solid result — keep this consistency going into your next exam."
  }
  if (score >= 50) {
    return `Decent attempt — a bit more review on ${subjectLabel} should push you past 75%.`
  }
  return `This is a weak spot — revisit ${subjectLabel} before your next attempt.`
}

function lastExamStatusLabel(tone) {
  if (tone === 'success') return 'Doing Well'
  if (tone === 'gold') return 'Needs Improvement'
  return 'Weak Area'
}

export function useStudentDashboardModule() {
  const auth = useAuthStore()

  const dashboardLoading = ref(false)
  const dashboardLoaded = ref(false)
  const dashboardError = ref('')
  const recentExams = ref([])
  const rooms = ref([])
  const predictiveModel = ref(null)

  const studentFirstName = computed(() => (
    auth.user?.first_name
    || String(auth.user?.name ?? 'Student').trim().split(/\s+/).filter(Boolean)[0]
    || 'Student'
  ))

  const notifications = computed(() => {
    const items = rooms.value.flatMap((room) => (
      asList(room.assigned_exams)
        .map((exam) => {
          const state = examNotificationState(exam)

          if (!['in_progress', 'available', 'retake', 'upcoming'].includes(state)) {
            return null
          }

          const dueAt = examDueAtValue(exam)
          const tier = noticeTier({ state, dueAt })
          const when = noticeWhen(exam, dueAt)

          return {
            id: `${room.id}-${exam.id}`,
            examId: exam.id,
            roomId: room.id,
            title: exam.title,
            roomName: room.name,
            roomCode: room.code,
            tone: notificationTone(state),
            badge: notificationLabel(state),
            message: studentExamAvailabilityText(exam),
            actionLabel: studentExamActionLabel(exam),
            subject: exam.subject,
            totalItems: exam.total_items,
            durationMinutes: exam.duration_minutes,
            timeValue: examTimeValue(exam),
            dueAt,
            assignedAt: examAssignedAtValue(exam),
            category: noticeCategory(tier),
            categoryLabel: noticeCategoryLabel(noticeCategory(tier), state),
            whenLabel: when.label,
            whenText: when.text,
            state,
          }
        })
        .filter(Boolean)
    ))

    return items.sort((left, right) => {
      const tierDelta = noticeTier(left) - noticeTier(right)
      if (tierDelta !== 0) return tierDelta

      // Near due date: soonest closing window first.
      if (noticeTier(left) === 0) return left.dueAt - right.dueAt

      // Newly uploaded: most recently assigned exam first.
      if (noticeTier(left) === 2) return right.assignedAt - left.assignedAt

      // Retake and everything else: fall back to the previous state/time order.
      const priorityDelta = notificationPriority(left.state) - notificationPriority(right.state)
      if (priorityDelta !== 0) return priorityDelta
      return left.timeValue - right.timeValue
    })
  })

  const recentExamCards = computed(() => (
    recentExams.value.map((attempt) => {
      const scorePercent = Number(attempt.score_percent ?? 0)

      return {
        ...attempt,
        scoreDisplay: `${scorePercent.toFixed(1)}%`,
        scoreTone: scoreTone(scorePercent),
        submittedAtText: attempt.submitted_at ? formatDateTime(attempt.submitted_at) : 'Submission time unavailable',
      }
    })
  ))

  // "Suggestion" — the student's most recently graded exam plus a short,
  // score-driven nudge, so they see what to do about it without a trip
  // to Analytics.
  const lastExamResult = computed(() => {
    const latest = recentExamCards.value[0]
    if (!latest) return null

    return {
      ...latest,
      suggestion: lastExamSuggestion(latest.score_percent, latest.subject),
      statusLabel: lastExamStatusLabel(latest.scoreTone),
    }
  })

  // "Am I ready?" — the single most decision-relevant signal for a licensure
  // exam review app, surfaced here instead of requiring a trip to Analytics.
  const readiness = computed(() => {
    const model = predictiveModel.value

    if (!model || model.status !== 'connected') {
      return {
        ready: false,
        label: 'Building Readiness Profile',
        tone: 'neutral',
        confidence: null,
        detail: model?.description ?? 'Take exams across all 6 core subjects to unlock this.',
      }
    }

    const isPass = model.prediction === 'PASS'

    return {
      ready: true,
      isPass,
      label: isPass ? 'On Track to Pass' : 'At Risk',
      tone: isPass ? 'success' : 'danger',
      confidence: Number(model.probability_percent ?? 0),
      detail: isPass
        ? 'Estimated confidence you will pass the LET, based on your exam history.'
        : 'Estimated confidence you will fail the LET if performance stays the same.',
    }
  })

  async function loadDashboard() {
    dashboardLoading.value = true
    dashboardError.value = ''

    try {
      const { data } = await studentDashboardApi.summary()

      recentExams.value = asList(data?.recent_exams)
      rooms.value = asList(data?.rooms)
    } catch (error) {
      dashboardError.value = firstApiError(error, 'Unable to load your dashboard right now.')
      recentExams.value = []
      rooms.value = []
    } finally {
      dashboardLoading.value = false
      dashboardLoaded.value = true
    }

    // Readiness prediction — kept as a separate, best-effort request so a
    // failure here never blocks the core dashboard (exam notices, rooms)
    // from loading and rendering.
    try {
      const { data } = await studentAnalyticsApi.summary()
      predictiveModel.value = data?.predictive_model ?? null
    } catch {
      predictiveModel.value = null
    }
  }

  onMounted(() => {
    loadDashboard()
  })

  return {
    studentFirstName,
    dashboardLoading,
    dashboardLoaded,
    dashboardError,
    notifications,
    lastExamResult,
    readiness,
    loadDashboard,
  }
}
