import { formatDateTime } from './useDashboardFormatters'
import { passes, scoreFor } from '@/utils/passingMark'

// Pure, framework-agnostic result-summary building -- deliberately not a Vue
// computed, so both the live exam-taking overlay's Vue state (useRoomsModule)
// and the standalone exam result page (which fetches its own attempt/feedback
// from GET /student/exam-attempts/:id, independent of that overlay's state)
// can build the identical summary from whatever attempt+feedback they have on
// hand, without duplicating this logic in two places.

const TOPIC_LEVEL_LABELS = {
  strong: 'Strong',
  average: 'Average',
  weak: 'Weak',
  needs_review: 'Needs Review',
}

const TOPIC_LEVEL_TONES = {
  strong: 'success',
  average: 'neutral',
  weak: 'gold',
  needs_review: 'danger',
}

export function studentExamTopicLevelLabel(level) {
  return TOPIC_LEVEL_LABELS[level] ?? 'Average'
}

export function topicLevelPillTone(level) {
  return TOPIC_LEVEL_TONES[level] ?? 'neutral'
}

// Cut-offs on the teachers' scale, so they move with the students' mark.
export function scoreBarTone(scorePercent, passing = null) {
  const score = Number(scorePercent ?? 0)
  if (score >= scoreFor(80, passing)) return 'success'
  if (score >= scoreFor(60, passing)) return 'neutral'
  if (score >= scoreFor(40, passing)) return 'gold'
  return 'danger'
}

/**
 * @param {{ attempt: object|null, feedback: object|null, questionsCount?: number }} params
 */
export function buildExamResultSummary({ attempt, feedback, questionsCount = 0 }) {
  const total = Math.max(0, Number(attempt?.total_items ?? questionsCount))
  const answered = Math.max(0, Number(attempt?.answered_count ?? 0))
  const correct = Math.max(0, Number(attempt?.correct_answers ?? 0))
  const incorrect = Math.max(answered - correct, 0)
  const missed = Math.max(total - answered, 0)
  const rawScore = Number(attempt?.score_percent ?? 0)
  const score = Number.isFinite(rawScore) ? rawScore : 0
  const submittedAtText = attempt?.submitted_at ? formatDateTime(attempt.submitted_at) : 'n/a'
  // The student's own passing mark, as the server judged this attempt.
  const passing = feedback?.pass_status?.passing_score ?? null

  let tone = 'needs-work'
  let label = 'Needs more review'
  let headline = 'Keep practicing and review the missed items.'
  let message = 'Focus on the questions you missed, then review the correct answers before your next attempt.'

  if (score >= scoreFor(90, passing)) {
    tone = 'excellent'
    label = 'Outstanding result'
    headline = 'Strong finish.'
    message = 'You cleared this exam with a high score. Review the full breakdown if you want to confirm every item.'
  } else if (passes(score, passing)) {
    tone = 'passing'
    label = 'Passing result'
    headline = 'You passed this attempt.'
    message = 'Your score is in a good range. Check the answer review for the few items that still need cleanup.'
  } else if (score >= scoreFor(60, passing)) {
    tone = 'review'
    label = 'Close, but needs review'
    headline = 'You are within reach.'
    message = 'You answered a fair share correctly, but there are still several items to revisit before the next attempt.'
  }

  // The backend already computes a much richer breakdown
  // (ExamResultFeedbackService::buildForAttempt) and ships it as `feedback`
  // alongside the attempt -- merge it in here so the result page can show
  // subject/topic breakdowns and recommendations instead of just the score.
  const safeFeedback = feedback ?? {}

  return {
    total,
    answered,
    correct,
    incorrect,
    missed,
    score,
    scoreDisplay: score.toFixed(2),
    submittedAtText,
    tone,
    label,
    headline,
    message,
    passStatus: safeFeedback.pass_status ?? null,
    subjectBreakdown: safeFeedback.subject_breakdown ?? [],
    topicBreakdown: safeFeedback.topic_breakdown ?? [],
    primaryFocus: safeFeedback.primary_focus ?? null,
    secondaryFocuses: safeFeedback.secondary_focuses ?? [],
    strength: safeFeedback.strength ?? null,
    readinessLevel: safeFeedback.readiness_level ?? null,
    recommendedActions: safeFeedback.recommended_actions ?? [],
    historicalReference: safeFeedback.historical_reference ?? null,
  }
}

/**
 * Subject Breakdown (flat) and Topic Breakdown (grouped by subject) come from
 * the backend as two separate lists that describe the same attempt -- merge
 * them here so the result page can render one nested "subject, then its
 * topics/subtopics" panel instead of two disconnected sections.
 *
 * @param {ReturnType<typeof buildExamResultSummary>} summary
 */
export function buildSubjectPerformancePanels(summary) {
  const topicsBySubject = new Map(
    (summary?.topicBreakdown ?? []).map((group) => [group.subject, group.topics]),
  )

  return (summary?.subjectBreakdown ?? []).map((subject) => ({
    ...subject,
    topics: topicsBySubject.get(subject.subject) ?? [],
  }))
}
