// The students' passing mark and the grade teachers see in its place. Mirrors
// App\Support\PassingMark: change the two together.
//
// Students are judged against the mark an administrator sets (75 to 95) and
// keep their real percentage. Teachers and administrators read the same score
// as a grade on the usual scale, where 75 is passing: the students' mark lands
// on 75, 0 stays 0 and 100 stays 100. At 75 nothing converts. Every other
// cut-off (60, 85, ...) is written on the teachers' scale and compared through
// scoreFor(), so it moves with the mark. Averages are of the real scores and
// converted last.

export const STAFF_MARK = 75
export const DEFAULT_MARK = 75
export const MIN_MARK = 75
export const MAX_MARK = 95

// `passing` as the server sends it ({ mark, staff_mark, converts }), or a mark.
// Anything else reads as 75: no conversion.
export function markOf(passing) {
  const mark = Number(passing?.mark ?? passing)
  return Number.isInteger(mark) && mark >= MIN_MARK && mark <= MAX_MARK ? mark : DEFAULT_MARK
}

// Whether teachers see grades: only once the mark is above their 75.
export function converts(passing) {
  return markOf(passing) > STAFF_MARK
}

export function passes(score, passing) {
  return score !== null && score !== undefined && Number(score) >= markOf(passing)
}

// The score (percent right) that reaches a cut-off written on the teachers'
// scale: scoreFor(75, 85) is 85, scoreFor(60, 85) is 68.
export function scoreFor(cutoff, passing) {
  const mark = markOf(passing)
  if (cutoff <= STAFF_MARK) return (cutoff * mark) / STAFF_MARK
  return mark + ((cutoff - STAFF_MARK) * (100 - mark)) / (100 - STAFF_MARK)
}

// A score as a grade on the teachers' scale, to two places.
export function toGrade(score, passing) {
  if (score === null || score === undefined || score === '' || Number.isNaN(Number(score))) return null
  const mark = markOf(passing)
  const value = Math.max(0, Math.min(100, Number(score)))
  const grade = value <= mark
    ? (value * STAFF_MARK) / mark
    : STAFF_MARK + ((value - mark) * (100 - STAFF_MARK)) / (100 - mark)
  return Math.round(grade * 100) / 100
}

// What a teacher's page plots and colours by: the grade once grades show, the
// score itself otherwise (they are the same number at 75).
export function staffValue(score, passing) {
  if (score === null || score === undefined || score === '') return null
  return converts(passing) ? toGrade(score, passing) : Number(score)
}

// A number to `digits` places without trailing zeros: 74.10 -> "74.1", 75 -> "75".
export function trimmedNumber(value, digits = 1) {
  return Number(value).toFixed(digits).replace(/\.0+$|(\.\d*?)0+$/, '$1')
}

// "Grade 74.1": a score as a teacher reads it once grades show. Pages keep
// their own score format for when they do not, so nothing changes at 75.
export function gradeText(score, passing, digits = 1) {
  if (score === null || score === undefined || score === '') return 'N/A'
  return `Grade ${trimmedNumber(toGrade(score, passing), digits)}`
}

// The (i) beside a grade on a teacher's page.
export function gradeTip(passing) {
  const mark = markOf(passing)
  return `Grades are on LNU's review scale, where ${STAFF_MARK} is passing. Students are held to a passing mark of ${mark}% (set by the administrator), so a score of ${mark}% is a grade of ${STAFF_MARK}. Averages are worked out from the scores, then converted. The student's actual score is in the details.`
}
