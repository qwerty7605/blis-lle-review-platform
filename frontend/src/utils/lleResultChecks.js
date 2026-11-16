import { now } from './clock'
import { early } from './fieldChecks'

/**
 * What a licensure (LLE) result may say, for the Licensure page's two ways
 * of recording one. The server's copy is app/Services/LleResultRules.php,
 * with the same sentences; change the two together.
 *
 * Refused (shown under the field, and the server says 422):
 *  - a pass with a general average under the passing average of its year
 *    (R.A. 9246, Sec. 17);
 *  - any result after the student passed, and a pass before another of
 *    their results;
 *  - an exam year after this one.
 *
 * Asked about before saving, since each can be right (unusualReasons(),
 * replacedReason()): a fail at the passing average or more (a subject under
 * 50%), a student not marked graduated, and changing a result on record.
 */

/**
 * The lowest passing average for an exam year. `passingAverages` is the
 * server's LleResultRules::PASSING_AVERAGES ({ firstYear: average }), sent
 * with the results and the batch roster; until it arrives, nothing is
 * checked here (the server still is).
 */
export function passingAverageFor(examYear, passingAverages) {
  const year = Number(String(examYear ?? '').trim())
  if (!Number.isInteger(year)) return null

  let mark = null

  // Whole-number keys come in ascending order.
  for (const [fromYear, average] of Object.entries(passingAverages ?? {})) {
    if (year >= Number(fromYear)) mark = Number(average)
  }

  return mark
}

function formatAverage(value) {
  return Number(value).toFixed(2)
}

function formatMark(mark) {
  return String(Number(Number(mark).toFixed(2)))
}

/** The year it is now, by the server's clock. */
export function currentYear() {
  return new Date(now()).getFullYear()
}

/** A whole four-digit exam year after this one. */
export function examYearProblem(examYear) {
  const raw = String(examYear ?? '').trim()
  if (!/^\d{4}$/.test(raw)) return ''

  const thisYear = currentYear()

  return Number(raw) > thisYear ? early(`The exam year cannot be later than this year (${thisYear}).`) : ''
}

/**
 * A pass under its year's passing average. It waits for the reader to
 * leave the field: 7 on the way to 78 is not a problem.
 */
export function belowPassingProblem({ result, rating, examYear, passingAverages }) {
  if (result !== 'pass') return ''

  const raw = String(rating ?? '').trim()
  if (raw === '' || !Number.isFinite(Number(raw))) return ''

  const mark = passingAverageFor(examYear, passingAverages)
  if (mark === null) return ''

  return Math.round(Number(raw) * 100) / 100 < mark
    ? `A general average under ${formatMark(mark)} is a failing grade (R.A. 9246, Sec. 17). Check the result or the average.`
    : ''
}

function sortedByYear(sittings) {
  return [...(sittings ?? [])].sort((a, b) => Number(a.exam_year) - Number(b.exam_year))
}

/** The student's pass in a year before this one, if any: then nothing more can be recorded. */
export function earlierPass(otherResults, examYear) {
  const year = Number(examYear)
  if (!Number.isInteger(year)) return null

  return sortedByYear(otherResults).find((sitting) => sitting.result === 'pass' && Number(sitting.exam_year) < year) ?? null
}

/** The student's first result in a year after this one, if any: then this year cannot be a pass. */
export function laterSitting(otherResults, examYear) {
  const year = Number(examYear)
  if (!Number.isInteger(year)) return null

  return sortedByYear(otherResults).find((sitting) => Number(sitting.exam_year) > year) ?? null
}

/**
 * A result that the student's other sittings rule out. `otherResults` is
 * their results for other exam years ([{ exam_year, result }]).
 */
export function sittingOrderProblem({ name, examYear, result, otherResults }) {
  const year = Number(String(examYear ?? '').trim())
  if (!Number.isInteger(year) || year < 1900) return ''

  const others = (otherResults ?? []).filter((sitting) => Number(sitting.exam_year) !== year)
  const passed = earlierPass(others, year)

  if (passed) {
    return early(`${name} passed the LLE in ${passed.exam_year}, so no result can be recorded for a later year.`)
  }

  const later = laterSitting(others, year)

  if (result === 'pass' && later) {
    return early(`${name} already has a result for ${later.exam_year}, and nothing can come after a pass. Record ${year} as Failed, or check the year.`)
  }

  return ''
}

const STATUS_LABELS = {
  enrolled: 'Enrolled',
  dropped: 'Dropped out',
  transferred: 'Transferred',
  on_leave: 'On leave',
  archived_unspecified: 'Archived, reason not recorded',
}

/**
 * Why a result that can be right still deserves a second look, as
 * sentences: a fail at the passing average or more (only when a subject was
 * under 50%), and a student not marked graduated (nobody sits the LLE before
 * graduating, but the End of School Year step may not have been run).
 */
export function unusualReasons({ result, rating, examYear, passingAverages, studentStatus }) {
  const reasons = []
  const raw = String(rating ?? '').trim()
  const mark = passingAverageFor(examYear, passingAverages)

  if (result === 'fail' && raw !== '' && mark !== null && Number.isFinite(Number(raw)) && Number(raw) >= mark) {
    reasons.push(`Failed with an average of ${formatAverage(raw)}. That happens only when a subject was under 50%.`)
  }

  if (result && studentStatus !== 'graduated') {
    const status = STATUS_LABELS[studentStatus]
    reasons.push(`Not marked graduated${status ? ` (${status})` : ''}. Only graduates can take the LLE.`)
  }

  return reasons
}

const RESULT_LABELS = { pass: 'Passed', fail: 'Failed' }

/**
 * Saving over a result on record. `whole`: the One Student form, which
 * replaces the whole record (date, average and remarks too), so any record
 * for the year counts. Otherwise (By Batch) only a changed result, or an
 * average on record that changes or is cleared.
 */
export function replacedReason({ examYear, recordedResult, recordedRating, result, rating, whole = false }) {
  if (!recordedResult) return ''

  const before = String(recordedRating ?? '').trim()
  const after = String(rating ?? '').trim()

  if (whole) {
    return `Already on record for ${examYear}: ${RESULT_LABELS[recordedResult]}${before !== '' ? `, average ${formatAverage(before)}` : ''}. Saving replaces it.`
  }

  if (result && result !== recordedResult) {
    return `On record as ${RESULT_LABELS[recordedResult]}. Saving changes it to ${RESULT_LABELS[result]}.`
  }

  if (before !== '' && (after === '' || Number(after) !== Number(before))) {
    return after === ''
      ? `On record with an average of ${formatAverage(before)}. Saving removes the average.`
      : `On record with an average of ${formatAverage(before)}. Saving changes it to ${formatAverage(after)}.`
  }

  return ''
}
