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
