// Plain helpers and settings of the Rooms pages (useRoomsModule and the
// composables it is built from): no state of their own.

// The width at which the exam screen stacks into one column and the question
// list becomes a bottom sheet. It has to match the exam layer's Responsive
// block in dashboard.css: this said 1200px while the stylesheet stacked at
// 900px, and in between the two disagreed about which column was which.
export const EXAM_PHONE_VIEWPORT_QUERY = '(max-width: 900px)'

// How often an open exam page asks the server about its attempt. Answers are
// saved the moment they are picked and the countdown runs in the browser, so
// this only has to notice what changes on the server -- above all the attempt
// being closed. At 4 seconds it was most of the server's work during an exam:
// every student downloading the whole attempt again fifteen times a minute.
export const EXAM_STATUS_CHECK_INTERVAL_MS = 30000

// How many times, a second apart, the page asks whether the exam has closed
// once its countdown runs out. The first answer is nearly always yes.
export const EXAM_TIME_UP_TRIES = 10

// How often an open Rooms page asks the server what has changed in the room
// on screen: an exam assigned or rescheduled, a student joined. This is the
// page a class sits on while it waits for an exam, so the number is also how
// much the whole room asks of the server while waiting: at a minute, about
// four requests a second for 230 students.
export const ROOM_LIVE_REFRESH_MS = 60000

export function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

export function extractDownloadFilename(contentDisposition, fallbackName) {
  if (typeof contentDisposition !== 'string' || contentDisposition.trim() === '') {
    return fallbackName
  }

  const utf8Match = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)
  if (utf8Match?.[1]) {
    try {
      return decodeURIComponent(utf8Match[1]).replace(/"/g, '').trim()
    } catch (error) {
      // Fall back to plain filename parsing below.
    }
  }

  const plainMatch = contentDisposition.match(/filename="?([^";]+)"?/i)
  if (plainMatch?.[1]) {
    return plainMatch[1].trim()
  }

  return fallbackName
}

export function saveDownloadedBlob(response, fallbackName) {
  if (typeof window === 'undefined' || typeof document === 'undefined') return

  const blob = response?.data instanceof Blob
    ? response.data
    : new Blob([response?.data ?? ''])

  const contentDisposition = response?.headers?.['content-disposition']
  const filename = extractDownloadFilename(contentDisposition, fallbackName)

  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

export async function resolveBlobApiError(error, fallbackMessage) {
  const blobPayload = error?.response?.data
  if (blobPayload instanceof Blob) {
    try {
      const text = await blobPayload.text()
      const parsed = JSON.parse(text)
      const message = String(parsed?.message ?? '').trim()
      if (message) return message
    } catch (parseError) {
      // Ignore and fall back.
    }
  }

  return firstApiError(error, fallbackMessage)
}

export function displayMemberRole(role) {
  const normalized = String(role ?? '').toLowerCase()
  if (normalized === 'admin') return 'Administrator'
  if (normalized === 'staff_master_examiner') return 'Teacher'
  return 'Student'
}

export function yearLevelLabel(value) {
  const numeric = Number(value)
  if (numeric === 1) return '1st Year'
  if (numeric === 2) return '2nd Year'
  if (numeric === 3) return '3rd Year'
  if (numeric === 4) return '4th Year'
  return 'Unassigned Year'
}

export function compareRoomMembers(left, right) {
  const nameCompare = String(left?.name ?? '').localeCompare(String(right?.name ?? ''), undefined, {
    sensitivity: 'base',
  })

  if (nameCompare !== 0) {
    return nameCompare
  }

  const studentIdCompare = String(left?.student_id ?? '').localeCompare(String(right?.student_id ?? ''), undefined, {
    numeric: true,
    sensitivity: 'base',
  })

  if (studentIdCompare !== 0) {
    return studentIdCompare
  }

  return Number(left?.id ?? 0) - Number(right?.id ?? 0)
}

export function sortRoomMembers(members) {
  return [...members].sort(compareRoomMembers)
}

export function buildRoomYearSummary(members) {
  const normalizedYearLevels = Array.from(new Set(
    members
      .map((member) => Number(member?.year_level))
      .filter((yearLevel) => Number.isFinite(yearLevel) && yearLevel > 0),
  )).sort((left, right) => left - right)

  if (normalizedYearLevels.length === 0) {
    return {
      count: 0,
      primary_year_level: null,
      label: 'Year level not set',
      is_mixed: false,
    }
  }

  if (normalizedYearLevels.length === 1) {
    return {
      count: 1,
      primary_year_level: normalizedYearLevels[0],
      label: yearLevelLabel(normalizedYearLevels[0]),
      is_mixed: false,
    }
  }

  return {
    count: normalizedYearLevels.length,
    primary_year_level: normalizedYearLevels[0],
    label: 'Mixed Year Levels',
    is_mixed: true,
  }
}

export function splitQuestionStemAndNumberedItems(questionText) {
  const normalized = String(questionText ?? '').replace(/\s+/g, ' ').trim()
  if (!normalized) {
    return { leadText: '', numberedItems: [] }
  }

  const firstNumberMatch = /(?:^|\s)1\.\s+/.exec(normalized)
  if (!firstNumberMatch) {
    return { leadText: normalized, numberedItems: [] }
  }

  const startsWithSpace = firstNumberMatch[0].startsWith(' ')
  const itemBlockStart = (firstNumberMatch.index ?? 0) + (startsWithSpace ? 1 : 0)
  const leadText = normalized.slice(0, itemBlockStart).trim()
  if (!leadText) {
    return { leadText: normalized, numberedItems: [] }
  }

  const itemBlock = normalized.slice(itemBlockStart).trim()
  const itemMatches = itemBlock.matchAll(/(\d+)\.\s*(.+?)(?=(?:\s+\d+\.\s*)|$)/g)
  const parsedItems = Array.from(itemMatches).map((match) => ({
    number: Number(match[1]),
    text: String(match[2] ?? '').trim(),
  }))

  if (parsedItems.length < 2) {
    return { leadText: normalized, numberedItems: [] }
  }

  const isSequential = parsedItems.every((item, index) => (
    index === 0 ? item.number === 1 : item.number === parsedItems[index - 1].number + 1
  ))

  const hasMostlyStatementText = parsedItems.filter((item) => /[A-Za-z]/.test(item.text)).length >= Math.max(2, Math.ceil(parsedItems.length * 0.7))

  if (!isSequential || !hasMostlyStatementText) {
    return { leadText: normalized, numberedItems: [] }
  }

  return {
    leadText,
    numberedItems: parsedItems.map((item) => item.text),
  }
}
