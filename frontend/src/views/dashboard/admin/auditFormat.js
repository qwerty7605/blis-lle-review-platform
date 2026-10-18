import { beatAt, now } from '@/utils/clock'

const ROLE_LABELS = {
  student: 'Student',
  staff_master_examiner: 'Teacher',
  faculty: 'Teacher',
  admin: 'Administrator',
}

const SHORT = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
const SHORT_WITH_YEAR = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
const EXACT = new Intl.DateTimeFormat(undefined, {
  year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit', timeZoneName: 'short',
})
const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })

function parse(value) {
  const at = new Date(value)
  return Number.isNaN(at.getTime()) ? null : at
}

export function roleLabel(role) {
  return ROLE_LABELS[String(role ?? '').toLowerCase()] ?? (role ? String(role) : '')
}

export function shortTime(value) {
  const at = parse(value)
  if (!at) return '—'
  return (at.getFullYear() === new Date(now()).getFullYear() ? SHORT : SHORT_WITH_YEAR).format(at)
}

export function exactTime(value) {
  const at = parse(value)
  return at ? EXACT.format(at) : ''
}

// "5 minutes ago", kept current while the page is open.
export function relativeTime(value) {
  const at = parse(value)
  if (!at) return ''
  const seconds = Math.round((at.getTime() - now()) / 1000)
  const abs = Math.abs(seconds)

  if (abs < 45) {
    beatAt(now() + 15000)
    return 'just now'
  }
  if (abs < 3600) {
    beatAt(now() + 60000)
    return RELATIVE.format(Math.round(seconds / 60), 'minute')
  }
  if (abs < 86400) return RELATIVE.format(Math.round(seconds / 3600), 'hour')
  if (abs < 86400 * 30) return RELATIVE.format(Math.round(seconds / 86400), 'day')
  if (abs < 86400 * 365) return RELATIVE.format(Math.round(seconds / (86400 * 30)), 'month')
  return RELATIVE.format(Math.round(seconds / (86400 * 365)), 'year')
}

export function actorName(log) {
  if (log.actor?.name) return log.actor.name
  if (log.actor_hint) return `Unknown (“${log.actor_hint}”)`
  return 'System'
}

export function actorSub(log) {
  if (log.actor) return roleLabel(log.actor.role) || log.actor.email || ''
  return log.actor_hint ? 'No matching account' : 'Automatic'
}

export function targetName(log) {
  const target = log.target ?? {}
  if (target.name) return target.name
  if (target.id) return `#${target.id}`
  return target.type_label ?? '—'
}

// "queued_count" -> "Queued count"
export function detailLabel(key) {
  const words = String(key).replace(/[_-]+/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

export function detailValue(value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (Array.isArray(value)) {
    if (value.every((item) => typeof item !== 'object')) {
      return value.length > 12 ? `${value.slice(0, 12).join(', ')} … (${value.length} in all)` : value.join(', ')
    }
    return JSON.stringify(value)
  }
  if (typeof value === 'object') {
    return Object.entries(value).map(([key, item]) => `${detailLabel(key)}: ${detailValue(item)}`).join('; ')
  }
  return String(value)
}
