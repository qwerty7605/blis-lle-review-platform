/*
 * The pass/fail estimate in the words the student sees. One place for it, so
 * the dashboard and the Analytics page cannot describe the same estimate two
 * different ways.
 */
// `audience` is who is reading: the student themselves ('self') or a teacher
// or admin looking at a student's profile ('staff').
export function describeReadiness(model, audience = 'self') {
  const who = audience === 'staff' ? 'this student' : 'you'

  if (!model || model.status !== 'connected') {
    return {
      ready: false,
      label: 'Building Readiness Profile',
      tone: 'neutral',
      confidence: null,
      detail: model?.description || (audience === 'staff'
        ? 'This student needs results across all 6 core subjects before an estimate is possible.'
        : 'Take exams across all 6 core subjects to unlock this.'),
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
      ? `Estimated confidence ${who} will pass the LET, based on exam history.`
      : `Estimated confidence ${who} will fail the LET if performance stays the same.`,
  }
}
