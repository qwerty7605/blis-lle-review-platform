import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { studentDashboardApi } from '@/api/studentDashboard.api'
import { studentAnalyticsApi } from '@/api/studentAnalytics.api'
import { now } from '@/utils/clock'
import { useLiveRefresh } from '@/utils/liveRefresh'
import { formatCompactDate, formatDateTime, parseDateTime } from './useDashboardFormatters'
import { describeReadiness } from './readiness'
import { markOf, passes, scoreFor } from '@/utils/passingMark'
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
  if (scheduleStart && scheduleStart.getTime() > now()) {
    return { label: 'Opens', text: formatShortDate(scheduleStart.getTime()) }
  }

  if (exam?.assigned_at) {
    return { label: 'Assigned', text: formatShortDate(examAssignedAtValue(exam)) }
  }

  return { label: '', text: 'n/a' }
}

// Against the student's passing mark (`passing`, from their analytics); the
// 50 is on the teachers' scale, so it moves with the mark.
function scoreTone(scorePercent, passing) {
  const score = Number(scorePercent ?? 0)
  if (passes(score, passing)) return 'success'
  if (score >= scoreFor(50, passing)) return 'gold'
  return 'danger'
}

function lastExamSuggestion(scorePercent, subject, passing) {
  const score = Number(scorePercent ?? 0)
  const subjectLabel = subject || 'this subject'

  if (passes(score, passing)) {
    return "Solid result — keep this consistency going into your next exam."
  }
  if (score >= scoreFor(50, passing)) {
    return `Decent attempt — a bit more review on ${subjectLabel} should push you past ${markOf(passing)}%.`
  }
  return `This is a weak spot — revisit ${subjectLabel} before your next attempt.`
}

function lastExamStatusLabel(tone) {
  if (tone === 'success') return 'Excellent'
  if (tone === 'gold') return 'Good'
  return 'Needs Improvement'
}

export function useStudentDashboardModule() {
  const auth = useAuthStore()

  const dashboardLoading = ref(false)
  const dashboardLoaded = ref(false)
  const dashboardError = ref('')
  const recentExams = ref([])
  const rooms = ref([])
  const predictiveModel = ref(null)
  const insightStats = ref(null)
  const weakAreaRaw = ref(null)

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

  // The student's passing mark, from the analytics this page also loads.
  const passingMark = computed(() => markOf(insightStats.value?.passing_threshold))

  const recentExamCards = computed(() => (
    recentExams.value.map((attempt) => {
      const scorePercent = Number(attempt.score_percent ?? 0)

      return {
        ...attempt,
        scoreDisplay: `${scorePercent.toFixed(1)}%`,
        scoreTone: scoreTone(scorePercent, passingMark.value),
        submittedAtText: attempt.submitted_at ? formatDateTime(attempt.submitted_at) : 'Submission time unavailable',
        submittedAtCompact: attempt.submitted_at ? formatCompactDate(attempt.submitted_at) : 'n/a',
      }
    })
  ))

  // "Performance feed" — every recent graded exam (not just the latest one),
  // each with a short score-driven nudge, so a student sees a trend at a
  // glance instead of a single data point.
  const performanceFeed = computed(() => (
    recentExamCards.value.map((attempt) => ({
      ...attempt,
      suggestion: lastExamSuggestion(attempt.score_percent, attempt.subject, passingMark.value),
      statusLabel: lastExamStatusLabel(attempt.scoreTone),
    }))
  ))

  // "Am I ready?" — the single most decision-relevant signal for a licensure
  // exam review app, surfaced here instead of requiring a trip to Analytics.
  const readiness = computed(() => describeReadiness(predictiveModel.value))

  // "How are you trending?" — real numbers from the same analytics summary
  // the readiness prediction already comes from (routes/api/student.php's
  // 'stats' block), for the outcome card's stat row.
  const outcomeStats = computed(() => {
    const stats = insightStats.value

    return {
      averageScoreDisplay: stats?.average_score_percent == null
        ? 'N/A'
        : `${Number(stats.average_score_percent).toFixed(1)}%`,
      focusSubjectsCount: Number(stats?.focus_subjects_count ?? 0),
      passingMark: passingMark.value,
    }
  })

  // A truthful "since your last attempt" delta — computed server-side from
  // the two most recent submitted attempts (routes/api/student.php), not a
  // fabricated trend. Split into parts (not one flat string) so the hero can
  // bold just the number, the way it bolds every other real stat. Null until
  // there are at least two attempts to compare.
  const scoreTrendParts = computed(() => {
    const delta = insightStats.value?.latest_score_delta
    if (delta === null || delta === undefined) return null

    const magnitude = Math.abs(Number(delta)).toFixed(1)
    if (Number(delta) > 0) return { lead: 'You climbed ', bold: `${magnitude} points`, tail: ' since your last attempt. ' }
    if (Number(delta) < 0) return { lead: 'You dropped ', bold: `${magnitude} points`, tail: ' since your last attempt. ' }
    return { lead: 'Your score held steady', bold: '', tail: ' since your last attempt. ' }
  })

  // Hero badge #1 — the student's real cohort (admission_year, see the
  // student-lifecycle migration), not a guessed "current academic period."
  const cohortBadgeText = computed(() => {
    const year = auth.user?.admission_year
    return year ? `Cohort ${year}` : ''
  })

  // Hero badge #2 — the soonest real thing on this student's plate (same
  // sort order as the notices list below), not a fabricated licensure date.
  const nextUpcomingBadgeText = computed(() => {
    const soonest = notifications.value[0]
    if (!soonest?.whenText || soonest.whenText === 'n/a') return ''
    return soonest.whenLabel ? `${soonest.whenLabel}: ${soonest.whenText}` : soonest.whenText
  })

  // Deep-links straight into Review Bot scoped to the student's actual
  // weakest area, mirroring the same subject/topic_id/subtopic_id query
  // convention StudentExamResultView.vue's "Review Your Weak Areas" uses --
  // falls back to an unscoped Review Bot link when no weak area is known yet
  // (e.g. no attempts, or every subject is already solid).
  const weakAreaReviewLink = computed(() => {
    const area = weakAreaRaw.value

    if (!area?.subject) {
      return { name: 'dashboard-review-bot' }
    }

    return {
      name: 'dashboard-review-bot',
      query: {
        subject: area.subject,
        ...(area.topic_id ? { topic_id: area.topic_id } : {}),
        ...(area.basis === 'subtopic' && area.subtopic_id ? { subtopic_id: area.subtopic_id } : {}),
      },
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

    await loadReadiness()
  }

  // Readiness prediction — kept as a separate, best-effort request so a
  // failure here never blocks the core dashboard (exam notices, rooms)
  // from loading and rendering.
  async function loadReadiness({ keepOnFailure = false } = {}) {
    try {
      const { data } = await studentAnalyticsApi.summary()
      predictiveModel.value = data?.predictive_model ?? null
      insightStats.value = data?.stats ?? null
      weakAreaRaw.value = data?.weak_area ?? null
    } catch {
      if (keepOnFailure) return

      predictiveModel.value = null
      insightStats.value = null
      weakAreaRaw.value = null
    }
  }

  /*
   * The quiet refresh behind useLiveRefresh: the notices are read again every
   * minute while this page is on screen, so an exam a teacher has just
   * assigned turns up without the page being reloaded. The readiness figures
   * only move when an exam is finished, and cost more to work out, so they
   * are read again only when the student comes back to the page.
   */
  async function refreshDashboardQuietly({ returning = false } = {}) {
    const { data } = await studentDashboardApi.summary()

    recentExams.value = asList(data?.recent_exams)
    rooms.value = asList(data?.rooms)
    dashboardError.value = ''

    if (returning) await loadReadiness({ keepOnFailure: true })
  }

  useLiveRefresh(refreshDashboardQuietly, {
    every: 60000,
    when: () => dashboardLoaded.value && !dashboardLoading.value,
  })

  onMounted(() => {
    loadDashboard()
  })

  return {
    studentFirstName,
    dashboardLoading,
    dashboardLoaded,
    dashboardError,
    notifications,
    performanceFeed,
    readiness,
    outcomeStats,
    scoreTrendParts,
    cohortBadgeText,
    nextUpcomingBadgeText,
    weakAreaReviewLink,
    loadDashboard,
  }
}
