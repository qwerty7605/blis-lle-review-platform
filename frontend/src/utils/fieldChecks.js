import { nextTick, reactive } from 'vue'
import { firstPasswordProblem } from './password'

/**
 * Field checks: what a form will not accept, said under the field before
 * the form is sent rather than after the server refuses it.
 *
 * A check is a function that returns '' when the field is fine, or the
 * problem as a sentence. A problem shows once the reader leaves the field or
 * presses the form's button, then follows their typing until it is fixed.
 * One that more typing cannot fix (a letter in a student ID, a number over
 * the limit) shows at once: return it wrapped in early().
 *
 * The rules mirror the server's validation in app/Http/Controllers/Api;
 * change the two together. A check here may be stricter than the server
 * (an email needs a dot in its domain), never looser.
 */

/** A problem shown while the reader is still typing. */
export function early(message) {
  return { message, early: true }
}

/** The sentence of a check's result, whichever form it came in. */
export function problemMessage(problem) {
  return typeof problem === 'string' ? problem : (problem?.message ?? '')
}

function text(value) {
  return String(value ?? '').trim()
}

export function requiredProblem(value, message) {
  return text(value) === '' ? message : ''
}

/** Over the limit only happens where the field has no maxlength of its own. */
export function lengthProblem(value, { label, max, min = 0 }) {
  const length = text(value).length

  if (length > max) return early(`${label} can be at most ${max.toLocaleString()} characters (now ${length.toLocaleString()}).`)
  if (length > 0 && length < min) return `${label} needs at least ${min} characters.`

  return ''
}

export const STUDENT_ID_PATTERN = /^\d{7,10}$/

export function studentIdProblem(value, { required = true, emptyMessage = 'Enter the student ID.' } = {}) {
  const id = text(value)

  if (id === '') return required ? emptyMessage : ''
  if (/\D/.test(id)) return early('Student ID can only contain numbers (0 to 9).')
  if (id.length > 10) return early(`Student ID must be 7 to 10 digits. This one has ${id.length}.`)
  if (id.length < 7) return `Student ID must be 7 to 10 digits. This one has ${id.length}.`

  return ''
}

export function emailProblem(value, { required = true, emptyMessage = 'Enter an email address.' } = {}) {
  const email = text(value)

  if (email === '') return required ? emptyMessage : ''
  if (/\s/.test(email)) return early('An email address cannot contain spaces.')
  if ((email.match(/@/g) ?? []).length > 1) return early('An email address has only one @.')
  if (!/^[^@\s]+@[^@\s.]+(\.[^@\s.]+)*\.[^@\s.]{2,}$/.test(email)) {
    return 'Enter a full email address, like name@lnu.edu.ph.'
  }

  return ''
}

/**
 * A whole number between min and max. Over max shows at once; under min
 * waits, as someone typing 1000 passes through 1, 10 and 100.
 */
export function wholeNumberProblem(value, { label, min, max, required = true }) {
  const raw = text(value)

  if (raw === '') return required ? `Enter ${label.toLowerCase()}.` : ''

  const number = Number(raw)

  if (!Number.isFinite(number)) return early(`${label} must be a number.`)
  if (!Number.isInteger(number)) return early(`${label} must be a whole number.`)
  if (number > max) return early(`${label} can be at most ${max.toLocaleString()}.`)
  if (number < min) return `${label} must be at least ${min.toLocaleString()}.`

  return ''
}

/** A number with up to `places` decimals, such as a 0 to 100 average. */
export function decimalProblem(value, { label, min, max, places = 2, required = false }) {
  const raw = text(value)

  if (raw === '') return required ? `Enter ${label.toLowerCase()}.` : ''

  const number = Number(raw)

  if (!Number.isFinite(number)) return early(`${label} must be a number.`)
  if (number > max || number < min) return early(`${label} must be from ${min} to ${max}.`)
  if ((raw.split('.')[1] ?? '').length > places) return early(`${label} can have at most ${places} decimal places.`)

  return ''
}

/** A four-digit year, as the server's 1900 to 2999 range takes it. */
export function yearProblem(value, { label = 'Year', required = false } = {}) {
  const raw = text(value)

  if (raw === '') return required ? `Enter the ${label.toLowerCase()}.` : ''
  if (!/^\d+$/.test(raw)) return early(`${label} must be a four-digit year, like 2026.`)
  if (raw.length > 4 || Number(raw) > 2999) return early(`${label} must be a four-digit year, like 2026.`)
  if (raw.length < 4 || Number(raw) < 1900) return `${label} must be a four-digit year, like 2026.`

  return ''
}

/** A filter's year, sent only once it is a whole year: "20" on the way to 2026 is not one. */
export function isFullYear(value) {
  return text(value) !== '' && !yearProblem(value)
}

/** The password policy (utils/password.js); blank is fine when it is optional. */
export function passwordProblem(value, { required = true, emptyMessage = 'Enter a password.' } = {}) {
  const password = String(value ?? '')

  if (password === '') return required ? emptyMessage : ''

  return firstPasswordProblem(password)
}

/**
 * The second copy of a password. A wrong character shows at once; a copy
 * that is right so far but shorter waits until the reader leaves the field.
 */
export function confirmationProblem(password, confirmation, { emptyMessage = 'Type the password again.' } = {}) {
  const first = String(password ?? '')
  const second = String(confirmation ?? '')

  if (second === '') return first === '' ? '' : emptyMessage
  if (second === first) return ''
  if (!first.startsWith(second)) return early('Passwords do not match.')

  return 'Passwords do not match.'
}

/**
 * Sign-in takes an email address or a student ID (AuthController::login).
 * Letters without an @ are neither, but may be an address half typed, so
 * that waits until the reader leaves the field.
 */
export function signInIdentifierProblem(value) {
  const identifier = text(value)

  if (identifier === '') return 'Enter your email address or student ID.'
  if (identifier.includes('@')) return emailProblem(identifier)
  if (/^\d+$/.test(identifier)) return studentIdProblem(identifier)

  return 'Enter your email address, or your student ID (numbers only).'
}

/** Room codes are letters and numbers (RoomController makes six). */
export function roomCodeProblem(value) {
  const code = text(value)

  if (code === '') return 'Enter the room code your teacher gave you.'
  if (/[^A-Za-z0-9]/.test(code)) return early('A room code has only letters and numbers, no spaces or symbols.')

  return ''
}

/** Two datetime-local or date values: the later one may not come first. */
export function orderProblem(start, end, message) {
  if (!start || !end) return ''

  return new Date(end).getTime() < new Date(start).getTime() ? early(message) : ''
}

/** A yyyy-mm-dd date that has not happened yet. */
export function futureDateProblem(value, label) {
  if (!value) return ''

  const now = new Date()
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`

  return String(value) > today ? early(`${label} cannot be later than today.`) : ''
}

/** A board exam's date taken, which has happened, in the year it is filed under. */
export function examDateProblem(takenAt, examYear) {
  const future = futureDateProblem(takenAt, 'Date taken')

  if (future || !takenAt || !/^\d{4}$/.test(String(examYear ?? '').trim())) return future

  const takenYear = String(takenAt).slice(0, 4)

  return takenYear !== String(examYear).trim()
    ? `This date is in ${takenYear}, but the exam year is ${String(examYear).trim()}.`
    : ''
}

/**
 * The state of one form's checks.
 *
 *   const checks = useFieldChecks('user-form', {
 *     email: () => emailProblem(form.email),
 *   })
 *
 *   <input v-model="form.email" class="text-input" v-bind="checks.bind('email')" />
 *   <FieldError :checks="checks" field="email" />
 *
 *   if (!checks.check()) return   // in the form's submit handler
 *
 * bind() gives the field its red edge, aria-invalid and the link to its
 * message, and notices when the reader leaves it. A 422 from the server is
 * put under the field it names with takeServerErrors(), and stays there
 * until that field is edited.
 */
export function useFieldChecks(name, checks) {
  const shown = reactive({})
  const fromServer = reactive({})

  function found(field) {
    const result = checks[field]?.()

    return { message: problemMessage(result), early: Boolean(result?.early) }
  }

  function error(field) {
    if (fromServer[field]) return fromServer[field]

    const { message, early: now } = found(field)

    return message && (now || shown[field]) ? message : ''
  }

  function errorId(field) {
    return `${name}-${field}-error`
  }

  function bind(field) {
    const message = error(field)

    return {
      'data-check': `${name}.${field}`,
      'aria-invalid': message ? 'true' : undefined,
      'aria-describedby': message ? errorId(field) : undefined,
      class: message ? 'is-invalid' : undefined,
      onBlur: () => {
        shown[field] = true
      },
      // A pick from a list or a calendar is finished when it is made.
      onChange: () => {
        shown[field] = true
      },
      onInput: () => {
        if (fromServer[field]) fromServer[field] = ''
      },
    }
  }

  function focus(field) {
    nextTick(() => {
      document.querySelector(`[data-check="${name}.${field}"]`)?.focus?.()
    })
  }

  /** For the form's button: shows every problem, and is true when there are none. */
  function check() {
    let first = null

    for (const field of Object.keys(checks)) {
      shown[field] = true

      if (first === null && found(field).message) first = field
    }

    if (first !== null) focus(first)

    return first === null
  }

  /** Whether the form would pass, without showing anything. */
  function passes() {
    return Object.keys(checks).every((field) => !found(field).message)
  }

  /** Puts a 422's messages under their fields; true when at least one had a place. */
  function takeServerErrors(apiError) {
    const errors = apiError?.response?.data?.errors ?? {}
    let first = null

    for (const [field, messages] of Object.entries(errors)) {
      if (!(field in checks)) continue

      fromServer[field] = Array.isArray(messages) ? String(messages[0]) : String(messages)
      first ??= field
    }

    if (first !== null) focus(first)

    return first !== null
  }

  function reset() {
    for (const field of Object.keys(shown)) delete shown[field]
    for (const field of Object.keys(fromServer)) delete fromServer[field]
  }

  return { bind, check, error, errorId, passes, reset, takeServerErrors }
}
