/*
 * The board exam model's answer in the words the reader sees. One place for
 * it, so the dashboard, the Analytics page and the staff's student profile
 * cannot describe the same result three different ways.
 *
 * The result itself, its wording and its note are decided on the server
 * (BoardExamOutlook.php): locked until a semester has all six subject exams,
 * then "On track" / "At risk" as an early warning in years 1 to 3, and
 * "Likely to pass" / "At risk of failing" in year 4.
 */
const LOCKED_MESSAGE = 'Complete all 6 subject exams this semester to unlock your result.'

function formatScore(score) {
  const value = Number(score)
  if (!Number.isFinite(value)) return ''

  return Number.isInteger(value) ? String(value) : value.toFixed(1)
}

// `audience` is who is reading: the student themselves ('self') or a teacher
// or admin looking at a student's profile ('staff'). The notes are written to
// the student, so for staff "your" becomes "this student's".
export function describeReadiness(model, audience = 'self') {
  const forStaff = (text) => (audience === 'staff'
    ? String(text ?? '')
      .replace('to unlock your result', "to unlock this student's result")
      .replace('your current semester scores', "this student's current semester scores")
    : String(text ?? ''))

  if (!model || model.status !== 'ready') {
    const progress = model?.progress ?? null

    return {
      ready: false,
      label: 'Locked',
      tone: 'neutral',
      confidence: null,
      modeLabel: model?.mode_label ?? '',
      detail: forStaff(model?.message || LOCKED_MESSAGE),
      // How far the semester in progress has got.
      progress: progress
        ? {
            text: `${progress.completed} of ${progress.required} done in the ${progress.semester}`,
            missing: Array.isArray(progress.missing) ? progress.missing : [],
          }
        : null,
      semesterLabel: '',
      riskFlags: [],
      focusAreas: [],
    }
  }

  const onTrack = model.outcome === 'on_track'

  return {
    ready: true,
    label: model.label,
    tone: onTrack ? 'success' : 'danger',
    confidence: Number(model.confidence ?? 0),
    modeLabel: model.mode_label ?? '',
    detail: forStaff(model.note),
    progress: null,
    semesterLabel: model.semester?.label ?? '',
    // The subjects the model treats as risk indicators, where the score is
    // on the wrong side of the model's own cut.
    riskFlags: (model.risk_flags ?? []).map((flag) => ({
      key: flag.key,
      text: `${flag.subject}: ${formatScore(flag.score)} (the model's risk line is ${String(flag.rule ?? '').includes('>') ? 'above' : 'at or below'} ${formatScore(flag.limit)})`,
    })),
    focusAreas: (model.focus_areas ?? []).map((area) => ({
      key: area.key,
      subject: area.subject,
      score: formatScore(area.score),
    })),
  }
}
