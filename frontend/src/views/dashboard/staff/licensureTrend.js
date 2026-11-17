/*
 * How the Licensure page writes passing rates (LicensurePassingTrend.vue and
 * LicensurePassingRateForm.vue): as the PRC does, a percentage to two places,
 * with the counts behind it.
 */

export function formatRate(value) {
  const number = Number(value)

  return value === null || value === undefined || !Number.isFinite(number) ? '—' : `${number.toFixed(2)}%`
}

/** "527 of 1,169 passed", or '' when only a rate is known. */
export function formatCounts(figure) {
  if (figure?.passers === null || figure?.passers === undefined || figure?.takers === null || figure?.takers === undefined) return ''

  return `${Number(figure.passers).toLocaleString()} of ${Number(figure.takers).toLocaleString()} passed`
}

/** LNU's rate against the national one, in percentage points. */
export function formatDifference(points) {
  if (points === null || points === undefined || !Number.isFinite(Number(points))) return '—'

  const value = Math.abs(Number(points))
  if (value < 0.005) return 'Same as national'

  return `${value.toFixed(2)} ${value === 1 ? 'point' : 'points'} ${Number(points) > 0 ? 'above' : 'below'}`
}

/**
 * The chart's series. Every year from the first with a figure to the last
 * is on the axis, so a year with none stays a gap in its line instead of
 * closing up the time between its neighbours.
 */
export function trendSeries(years) {
  const rows = [...(years ?? [])].sort((a, b) => Number(a.exam_year) - Number(b.exam_year))
  const series = { labels: [], lnu: [], national: [], lnuDetails: [], nationalDetails: [] }
  if (rows.length === 0) return series

  const byYear = new Map(rows.map((row) => [Number(row.exam_year), row]))
  const first = Number(rows[0].exam_year)
  const last = Number(rows[rows.length - 1].exam_year)

  for (let year = first; year <= last; year += 1) {
    const row = byYear.get(year)
    series.labels.push(String(year))
    series.lnu.push(row?.lnu ? Number(row.lnu.passing_rate) : null)
    series.national.push(row?.national ? Number(row.national.passing_rate) : null)
    series.lnuDetails.push(row?.lnu ? [formatCounts(row.lnu), row.lnu.from === 'entered' ? 'entered' : ''].filter(Boolean).join(', ') : '')
    series.nationalDetails.push(row?.national ? formatCounts(row.national) : '')
  }

  return series
}
