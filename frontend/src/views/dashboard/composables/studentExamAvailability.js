import { beatAt, now } from '@/utils/clock'
import {
  formatDateTime,
  formatExamSchedule,
  parseDateTime,
} from './useDashboardFormatters'

export function examHasQuestionSet(exam) {
  if (Array.isArray(exam?.question_bank_ids) && exam.question_bank_ids.length > 0) {
    return true
  }

  const legacyQuestionBankId = Number(exam?.question_bank_id ?? 0)
  return Number.isFinite(legacyQuestionBankId) && legacyQuestionBankId > 0
}

export function examScheduleStart(exam) {
  return exam?.schedule_start_at ?? exam?.scheduled_at ?? null
}

export function examScheduleEnd(exam) {
  return exam?.schedule_end_at ?? null
}

export function studentMaxAttempts(exam) {
  const resolvedMaxAttempts = Number(exam?.student_max_attempts)

  if (Number.isFinite(resolvedMaxAttempts) && resolvedMaxAttempts > 0) {
    return resolvedMaxAttempts
  }

  return exam?.one_take_only ? 1 : 2
}

export function studentExamAttemptState(exam) {
  return String(exam?.student_attempt_state ?? 'not_started').toLowerCase()
}

export function isStudentExamInProgress(exam) {
  return studentExamAttemptState(exam) === 'in_progress'
}

export function isStudentExamCompleted(exam) {
  return studentExamAttemptState(exam) === 'submitted'
}

export function studentSubmittedAttempts(exam) {
  const submittedAttempts = Number(exam?.student_submitted_attempts)

  if (Number.isFinite(submittedAttempts) && submittedAttempts >= 0) {
    return submittedAttempts
  }

  return isStudentExamCompleted(exam) ? 1 : 0
}

export function studentAttemptsRemaining(exam) {
  return Math.max(0, studentMaxAttempts(exam) - studentSubmittedAttempts(exam))
}

export function studentSubmittedAttemptId(exam) {
  const attemptId = Number(exam?.student_attempt_id ?? 0)
  return Number.isFinite(attemptId) && attemptId > 0 ? attemptId : null
}

export function isStudentExamRetakeLimitReached(exam) {
  return isStudentExamCompleted(exam) && studentAttemptsRemaining(exam) <= 0
}

/*
 * Both of these read the shared clock (utils/clock.js) rather than
 * Date.now(), and name the moment their answer will change. A page that asks
 * "has it opened yet?" while drawing itself is drawn again when it has: Take
 * Exam turns on at the exam's start time, without the page being reloaded.
 */
export function isStudentExamUpcoming(exam) {
  const scheduleStart = parseDateTime(examScheduleStart(exam))
  if (!scheduleStart) return false

  const upcoming = scheduleStart.getTime() > now()
  if (upcoming) beatAt(scheduleStart.getTime())

  return upcoming
}

export function isStudentExamEnded(exam) {
  const scheduleEnd = parseDateTime(examScheduleEnd(exam))
  if (!scheduleEnd) return false

  const ended = scheduleEnd.getTime() < now()
  if (!ended) beatAt(scheduleEnd.getTime())

  return ended
}

export function canStudentTakeExam(exam) {
  if (!examHasQuestionSet(exam)) return false
  if (isStudentExamUpcoming(exam)) return false
  if (isStudentExamEnded(exam)) return false
  if (isStudentExamRetakeLimitReached(exam)) return false

  return true
}

/*
 * The deadline of an attempt still under way, while it has not passed. After
 * the exam's end time this is what lets a student who started before it come
 * back to finish: their full duration, or the end time itself when the exam
 * ends for everyone there (ends_at_close; the server set the deadline so).
 */
export function studentAttemptFinishBy(exam) {
  if (!isStudentExamInProgress(exam)) return null

  const deadline = parseDateTime(exam?.student_attempt_expires_at)
  if (!deadline) return null

  if (deadline.getTime() <= now()) return null
  beatAt(deadline.getTime())

  return exam.student_attempt_expires_at
}

export function canStudentOpenExam(exam) {
  if (isStudentExamRetakeLimitReached(exam) && studentSubmittedAttemptId(exam)) {
    return true
  }

  if (isStudentExamEnded(exam) && studentAttemptFinishBy(exam)) {
    return true
  }

  return canStudentTakeExam(exam)
}

export function studentExamActionLabel(exam) {
  if (isStudentExamInProgress(exam)) return 'Resume Exam'
  if (isStudentExamCompleted(exam)) {
    return isStudentExamRetakeLimitReached(exam) ? 'Review Result' : 'Retake Exam'
  }

  return 'Take Exam'
}

/*
 * An exam the teacher archived from the room takes no new attempts; the server
 * refuses them. What is left for the student is finishing an attempt already
 * under way, or reading the result of one already handed in.
 */
export function archivedExamAction(exam) {
  if (isStudentExamInProgress(exam)) return 'resume'
  if (isStudentExamCompleted(exam) && studentSubmittedAttemptId(exam)) return 'result'

  return null
}

export function archivedExamActionLabel(exam) {
  return archivedExamAction(exam) === 'resume' ? 'Resume Exam' : 'Review Result'
}

export function archivedExamStatusText(exam) {
  const action = archivedExamAction(exam)

  if (action === 'resume') return 'Archived (you can still finish your attempt)'
  if (action === 'result') return 'Archived (review only)'

  return 'Archived (no longer open)'
}

export function studentExamAvailabilityText(exam) {
  if (!examHasQuestionSet(exam)) return 'Not available (no question set linked)'

  if (isStudentExamUpcoming(exam)) {
    return `Available on ${formatExamSchedule(examScheduleStart(exam), examScheduleEnd(exam))}`
  }

  if (isStudentExamEnded(exam)) {
    return `Window ended on ${formatDateTime(examScheduleEnd(exam))}`
  }

  if (isStudentExamRetakeLimitReached(exam)) {
    return studentMaxAttempts(exam) === 1
      ? 'Completed (review only)'
      : 'Retake limit reached (review only)'
  }

  if (isStudentExamInProgress(exam)) {
    return 'In progress (resume anytime)'
  }

  if (isStudentExamCompleted(exam)) {
    const remaining = studentAttemptsRemaining(exam)

    if (remaining === 1) {
      return '1 retake remaining'
    }

    return `Available for retake (${remaining} attempts left)`
  }

  if (examScheduleEnd(exam)) {
    return `Available until ${formatDateTime(examScheduleEnd(exam))}`
  }

  return 'Available now'
}
