/*
 * The Estimated Outcome in the words the reader sees. One place for it, so
 * the dashboard, the Analytics page and the staff's student profile cannot
 * describe the same result three different ways.
 *
 * The result itself is decided on the server (BoardExamOutlook.php): locked
 * until a pre-test or mock board series is complete; then the estimated
 * chance of passing from the most recently completed one, "At risk" when the
 * chance of failing is 25% or more, otherwise "Not at risk". The same words
 * for every student.
 */
const LOCKED_MESSAGE = 'Your Estimated Outcome appears after you finish all six subjects of a pre-test or mock board.'

function formatScore(score) {
  const value = Number(score)
  if (score === null || score === undefined || !Number.isFinite(value)) return '—'

  return Number.isInteger(value) ? String(value) : value.toFixed(1)
}

function formatDay(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

// "2026 Pre-test: 4 of 6 subjects done; missing: IT, Selection and Acquisition"
function progressText(series) {
  const missing = Array.isArray(series.missing) ? series.missing : []

  return `${series.name}: ${series.done} of ${series.required} subjects done`
    + (missing.length ? `; missing: ${missing.join(', ')}` : '')
}

// `audience` is who is reading: the student themselves ('self') or a teacher
// or admin looking at a student's profile ('staff'). The words are written to
// the student, so for staff "you" becomes "this student".
export function describeReadiness(model, audience = 'self') {
  const forStaff = (text) => (audience === 'staff'
    ? String(text ?? '')
      .replace('Your Estimated Outcome appears after you finish', "This student's Estimated Outcome appears after they finish")
      .replace('whether you will pass', 'whether this student will pass')
    : String(text ?? ''))

  const inProgress = (model?.in_progress ?? []).map((series) => ({ key: series.name, text: progressText(series) }))

  if (!model || model.status !== 'ready') {
    return {
      ready: false,
      label: 'Locked',
      tone: 'neutral',
      percent: null,
      headline: '',
      detail: forStaff(model?.message || LOCKED_MESSAGE),
      seriesText: '',
      scores: [],
      inProgress,
    }
  }

  const completed = formatDay(model.series?.completed_at)

  return {
    ready: true,
    label: model.label,
    // Amber for "At risk", never red: it reads as "pay attention".
    tone: model.at_risk ? 'danger' : 'success',
    percent: Number(model.percent_passing ?? 0),
    headline: `Estimated chance of passing: ${model.percent_passing}%`,
    detail: forStaff(model.note),
    seriesText: `Based on ${model.series?.name ?? 'a review series'} (${model.series?.type_label ?? ''})${completed ? `, completed ${completed}` : ''}`,
    scores: (model.scores ?? []).map((row) => ({ key: row.key, subject: row.subject, score: formatScore(row.score) })),
    inProgress,
  }
}

const YEAR_WORDS = { 3: '3rd year', 4: '4th year', 5: '5th year' }

function yearText(level) {
  return YEAR_WORDS[level] ?? `year ${level}`
}

// "Battery exams, 2nd Semester, AY 2026-2027 (3rd year): missing IT; an early
// estimate needs all six subjects in one semester."
function batteryProgressText(pending) {
  const missing = Array.isArray(pending?.missing) ? pending.missing : []

  return `Battery exams, ${pending.label} (${yearText(pending.year_level)}): missing ${missing.join(', ')}; an early estimate needs all six subjects in one semester.`
}

/*
 * The staff profile's Estimated Outcome card, with the early estimate from
 * battery exams (BatteryEarlyEstimate.php) in it. That shows only where the
 * Estimated Outcome is still locked and the model in use is ready for
 * battery exams; anything else reads as describeReadiness() does. Staff
 * only: students never get `early_estimate`.
 */
export function describeStaffReadiness(model, early) {
  const base = describeReadiness(model, 'staff')
  if (base.ready || !early) return base

  const pending = early.pending ? [{ key: 'battery', text: batteryProgressText(early.pending) }] : []

  if (early.status !== 'ready') {
    return early.status === 'locked' ? { ...base, inProgress: [...base.inProgress, ...pending] } : base
  }

  return {
    ready: true,
    early: true,
    label: early.label,
    tone: early.at_risk ? 'danger' : 'success',
    percent: Number(early.percent_passing ?? 0),
    headline: `Early estimate of the chance of passing: ${early.percent_passing}%`,
    detail: early.note,
    seriesText: `Based on battery exams, ${early.semester?.label ?? 'one semester'} (${yearText(early.semester?.year_level)})`,
    scoresTitle: 'Subject scores that semester',
    scores: (early.scores ?? []).map((row) => ({ key: row.key, subject: row.subject, score: formatScore(row.score) })),
    // What is still needed for the Estimated Outcome itself, then any later
    // semester short of a subject.
    inProgress: [...base.inProgress, ...pending],
    lockedDetail: base.detail,
  }
}
