// What a New Exam form starts with: the standard values, or the teacher's own
// from Settings (kept with their account as preferences.exam_defaults).
export const STANDARD_EXAM_DEFAULTS = Object.freeze({
  total_items: 60,
  duration_minutes: 90,
  one_take_only: false,
  // Each student gets their own order, and their own pick within each set
  // when the sets hold more questions than the exam asks for.
  shuffle_questions: true,
  // Answers shown once the student is done with the exam, and Review Bot
  // practice from it opening at that moment too; Hidden keeps both closed.
  results_visibility_mode: 'visible',
  answers_shown_after: 'last_attempt',
})

export function examDefaultsFor(user) {
  const own = user?.preferences?.exam_defaults ?? {}
  const number = (value, fallback) => (Number.isInteger(Number(value)) && Number(value) > 0 ? Number(value) : fallback)

  return {
    total_items: number(own.total_items, STANDARD_EXAM_DEFAULTS.total_items),
    duration_minutes: number(own.duration_minutes, STANDARD_EXAM_DEFAULTS.duration_minutes),
    one_take_only: Boolean(own.one_take_only ?? STANDARD_EXAM_DEFAULTS.one_take_only),
    shuffle_questions: Boolean(own.shuffle_questions ?? STANDARD_EXAM_DEFAULTS.shuffle_questions),
    results_visibility_mode: ['hidden', 'visible'].includes(own.results_visibility_mode)
      ? own.results_visibility_mode
      : STANDARD_EXAM_DEFAULTS.results_visibility_mode,
    answers_shown_after: ['each_attempt', 'last_attempt'].includes(own.answers_shown_after)
      ? own.answers_shown_after
      : STANDARD_EXAM_DEFAULTS.answers_shown_after,
  }
}
