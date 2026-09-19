/**
 * The password policy, mirrored from the backend.
 *
 * These regexes deliberately match Laravel's own Rules\Password definitions
 * character for character -- mixedCase() is \p{Ll} plus \p{Lu}, numbers() is
 * \p{N}, symbols() is \p{Z}|\p{S}|\p{P}. A looser client check (say
 * /[^A-Za-z0-9]/ for symbols) would accept an accented letter the server then
 * rejects, and "the form said it was fine" is a miserable thing to debug.
 *
 * The policy itself lives in AppServiceProvider::configurePasswordPolicy();
 * if it changes there, change it here too.
 */
export const PASSWORD_MIN_LENGTH = 8

export const PASSWORD_RULES = [
  {
    key: 'length',
    label: `At least ${PASSWORD_MIN_LENGTH} characters`,
    test: (pw) => pw.length >= PASSWORD_MIN_LENGTH,
  },
  { key: 'lower', label: 'One lowercase letter', test: (pw) => /\p{Ll}/u.test(pw) },
  { key: 'upper', label: 'One uppercase letter', test: (pw) => /\p{Lu}/u.test(pw) },
  { key: 'number', label: 'One number', test: (pw) => /\p{N}/u.test(pw) },
  { key: 'symbol', label: 'One special character', test: (pw) => /[\p{Z}\p{S}\p{P}]/u.test(pw) },
]

/** Which requirements a password currently satisfies, for a live checklist. */
export function passwordChecklist(password) {
  const pw = String(password ?? '')

  return PASSWORD_RULES.map((rule) => ({
    key: rule.key,
    label: rule.label,
    met: rule.test(pw),
  }))
}

export function passwordIsValid(password) {
  return passwordChecklist(password).every((item) => item.met)
}

/** First unmet requirement, phrased for an error line. */
export function firstPasswordProblem(password) {
  const missing = passwordChecklist(password).find((item) => !item.met)

  return missing ? `Password needs: ${missing.label.toLowerCase()}.` : ''
}

/**
 * Strength purely as "how much of the policy is satisfied". Nothing here
 * estimates entropy -- it tracks the requirements the server will actually
 * enforce, so a full bar means the form will be accepted.
 */
export function passwordStrength(password) {
  const met = passwordChecklist(password).filter((item) => item.met).length
  const pct = Math.round((met / PASSWORD_RULES.length) * 100)

  if (met === PASSWORD_RULES.length) {
    return { pct, color: 'var(--lnu-success)', label: 'Meets all requirements' }
  }

  if (met >= 3) return { pct, color: 'var(--lnu-gold)', label: 'Almost there' }

  return { pct: Math.max(pct, 10), color: 'var(--lnu-danger)', label: 'Too weak' }
}
