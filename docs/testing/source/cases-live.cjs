// The live-site edition of the black box test cases.
//
// Built from the local edition (cases-bb1.cjs, cases-bb2.cjs): the cases that
// cannot or should not be run on the live site are taken out, and the ones
// that name a local account or a local number are reworded. A case added to
// the local edition is in this one too, unless it is taken out below.
//
// The site's test accounts are not written here. They are %TOKENS%, filled in
// by build-live.cjs from live-accounts.json, which is not kept in git.
const c = (k, o, p, d, s, e) => ({ k, o, p, d, s, e })

const local = [...require('./cases-bb1.cjs'), ...require('./cases-bb2.cjs')]

// ---------------------------------------------------------------- taken out
const REMOVED = new Set([
  // Needs an account the live site does not have, or is covered elsewhere.
  'login-student-id', 'reg-id-taken',
  // The live site already has published Review Bot questions.
  'rb-student-before',
  // Would send real email to addresses that do not exist.
  'exp-email-one', 'exp-email-all',
  // Depend on the live site having no licensure results at all.
  'lle-model-left-out',
  // Would disturb real users: everyone locked out, registration closed,
  // the legal text changed, every student moved up a year.
  'set-reg-off', 'set-reg-on', 'set-terms-edit',
  'ye-groups', 'ye-cancel', 'ye-apply', 'ye-check', 'ye-warning', 'ye-restore-graduate',
])

const SECTION_TITLES = {
  'ADMIN: END OF SCHOOL YEAR (RUN THIS MODULE LAST)': 'ADMIN: END OF SCHOOL YEAR (LOOK ONLY. DO NOT CLOSE THE SCHOOL YEAR)',
  'TEACHER: EXPORTS AND EMAILED REPORTS': 'TEACHER: EXPORTS',
}

// ---------------------------------------------------------------- the same words, the live accounts
// (The lookbehinds keep "qa.teacher@example.com" from being read as the local teacher account.)
const REPLACEMENTS = [
  [/qa2400001@example\.com/g, 'qa.student1@example.com'],
  [/qa2400002@example\.com/g, 'qa.student2@example.com'],
  [/qa2400003@example\.com/g, 'qa.student3@example.com'],
  [/qa\.reg1@lnu\.edu\.ph/g, 'YOUR-LNU-EMAIL'],
  [/(?<![\w.])student1@example\.com/g, '%STUDENT_EMAIL%'],
  [/teacher1@example\.com\nPassword: pass/g, 'qa.teacher@example.com\nPassword: Test@1234'],
  [/(?<![\w.])admin@example\.com/g, '%ADMIN_EMAIL%'],
  [/(?<![\w.])teacher@example\.com/g, '%TEACHER_EMAIL%'],
  [/(?<![\w.])student@example\.com/g, '%STUDENT_EMAIL%'],
  [/(%ADMIN_EMAIL%)\nPassword: pass\b/g, '$1\nPassword: %ADMIN_PASSWORD%'],
  [/(%TEACHER_EMAIL%)\nPassword: pass\b/g, '$1\nPassword: %TEACHER_PASSWORD%'],
  [/(%STUDENT_EMAIL%)\nPassword: pass\b/g, '$1\nPassword: %STUDENT_PASSWORD%'],
  [/nobody@example\.com\nPassword: pass\b/g, 'nobody@example.com\nPassword: Anything@123'],
  [/\b24000(\d\d)\b/g, '99000$1'],
  [/By Teacher User/g, 'By the name of Teacher A'],
  [/Teacher User/g, 'Teacher A'],
  [/Admin User/g, 'the Admin'],
]

// ---------------------------------------------------------------- reworded for the live site
const OVERRIDES = {
  'login-student-email': {
    e: 'The student is signed in and the Dashboard opens with "Continue your journey," and the student\'s first name. The side menu shows Dashboard, Rooms, Review Bot, Analytics.',
  },
  'login-teacher': {
    e: 'The teacher Dashboard opens with "Welcome back," and the teacher\'s first name. The side menu shows Dashboard, Library, Review Bot, Room, Students, Licensure, Exams, Settings.',
  },
  'login-throttle': {
    d: 'Email: %STUDENT_EMAIL%\nPassword: wrong1 (typed 6 times)',
    s: ['Open the sign-in page', 'Sign in with the wrong password 5 times within one minute', 'Try a 6th time', 'Wait one minute and sign in with the correct password'],
  },
  'reg-valid': {
    p: 'Registration is allowed. Documents read and agreed. You have an LNU email that you can open and that has no account on the site yet (written here as YOUR-LNU-EMAIL).',
  },
  'reg-resend': {
    s: ['Click "Resend verification email"', 'Look at the button', 'Check the inbox of YOUR-LNU-EMAIL'],
    e: 'The message "If that address still needs verifying, a new link is on its way." is shown and a new verification email arrives. The button now reads "Resend again in 20s", counts down, and cannot be clicked.',
  },
  'forgot-valid': {
    e: '"If that address has an account, a reset link is on its way." is shown. An email arrives with a "Choose a new password" link.',
  },
  'forgot-throttle': {
    p: 'One reset link was already requested for this email (@forgot-valid@)',
    d: 'Email: YOUR-LNU-EMAIL',
    s: ['Open the Forgot Your Password page', 'Request a reset link for the same email 2 more times (3 within the hour in all)', 'Request once more'],
    e: 'Requests up to the third show the normal message. The fourth shows "Too Many Attempts."',
  },
  'users-weak-pw': { d: 'Password: pass\nOther fields valid' },
  'users-filters': {
    e: 'Each filter shows only accounts that match it: teachers only, then 2nd year students only (Quality Tester Three is among them), then inactive accounts only ("No users found" when every account is active).',
  },
  'users-self-disable': { d: 'Own account: the Admin account you are signed in with' },
  'users-self-unverify': { d: 'Own account: the Admin account you are signed in with' },
  'users-self-role': { d: 'Own account: the Admin account you are signed in with\nRole: Teacher' },
  'lib-empty': {
    p: 'Signed in as Teacher A, who has not uploaded a question bank yet (skip this test if the account already has one)',
  },
  'exam-empty': {
    p: 'Signed in as Teacher A, who has not created an exam yet (skip this test if the account already has one)',
  },
  'tdash-numbers': {
    p: 'Teacher A has the room QA Room 1A with 2 students, and had no other room before these tests',
  },
  'tdash-open': {
    e: 'The page shows "Welcome back," and the teacher\'s first name, a sentence with the number of graded results and students, and four cards: Managed Rooms, Managed Exams, Completion Rate, Average Score.',
  },
  'analytics-subjects': {
    e: 'The board subjects are listed. Cataloging and Classification shows a percentage with Last and Best scores. A subject with no finished exam shows "NO FINISHED EXAM YET".',
  },
  'dir-filters': {
    e: '2nd Year lists only 2nd year students, with Quality Tester Three among them. Enrolled lists only enrolled students. Archived records lists only archived students, or "No students found" if there are none.',
  },
  'exp-activity': {
    e: 'The latest exports are listed with their date, time and the teacher\'s name.',
  },
  'lle-open': {
    p: 'Signed in as Teacher A',
    e: 'The page shows "Record a licensure result", the "Exam Outcome model" panel and "Recorded results".',
  },
  'lle-filter': {
    p: 'The Failed result for Quality Tester One exists (@lle-correct@)',
    d: 'Search: 9900001\nOutcome: Passed, then Failed',
    s: ['Search 9900001', 'Choose the outcome Passed', 'Choose Failed', 'Set it back to All outcomes and clear the search'],
    e: 'The search shows the row of Quality Tester One. With Passed chosen the row disappears; with Failed chosen it is shown again.',
  },
  'lle-model-panel': {
    p: 'Signed in as Teacher A, on Licensure. No retrained model is in use yet.',
  },
  'lle-model-check': {
    p: 'Signed in as Admin, on Licensure. Skip this test if "New since the last training" shows 10 or more: the real model must not be retrained by a test.',
  },
  'lle-revert-hidden': {
    p: 'Signed in as Admin, on Licensure. Only version 1 is listed in the version table (skip this test if there are more).',
  },
  'lle-remove': {
    e: 'The row of Quality Tester One is removed from Recorded results.',
  },
  'set-banner': {
    d: 'Announcement Banner: Notice: a system check is in progress.',
  },
  'aroom-open': {
    e: 'The room opens with its Room Code and the Class List of students. There is no Add Room button for the admin.',
  },
  'ye-open': {
    p: 'Signed in as Admin. First edit Quality Tester Three and set Year Level to 4th Year. DO NOT click "Review and confirm" anywhere in this module: closing the school year changes every real student.',
  },
  'ye-untick': { p: 'End of School Year page is open. Do not click "Review and confirm".' },
  'ye-list-invalid': { p: 'End of School Year page is open. Do not click "Review and confirm".' },
  'ye-list': {
    p: 'Quality Tester Three is 4th Year. Do not click "Review and confirm".',
    s: ['Type the Student ID in the registrar\'s list', 'Click Apply list', 'Afterwards leave the page with Back to Users, then edit Quality Tester Three back to 2nd Year'],
    e: 'A report says 1 ID was read and 1 finishing student is ticked to graduate. Quality Tester Three stays ticked. Nothing is changed in the student records.',
  },
}

// ---------------------------------------------------------------- added for this edition only
// (Locally these two are white box tests; they need Word, not the server.)
const ADDED_AFTER = {
  'lib-pdf': [
    c('lib-highlight', 'To verify that the correct answer is read from a highlight colour, not only from red text.',
      'A copy of the sample file in which the red text is made black and the correct choices are highlighted yellow instead (make it in Word)', 'File: Sample-Highlighted.docx',
      ['Make the copy in Word and save it', 'Click Add Questions and choose it', 'Compare the answers shown with the answer key', 'Close without saving'],
      'The same 10 answers are detected: B, C, A, D, B, A, C, D, B, A.'),
    c('lib-two-marks', 'To verify that a question with two marked choices gets no guessed answer and the teacher is warned.',
      'A copy of the sample file in which choice C of item 1 is also typed in red (make it in Word)', 'File: Sample-Two-Marks.docx',
      ['Make the copy in Word and save it', 'Click Add Questions and choose it', 'Read Parser Notes and item 1', 'Close without saving'],
      'Parser Notes shows "Question 1 has more than one marked choice (B, C)." Item 1 has no answer. Items 2 to 10 keep their answers.'),
  ],
}

// ---------------------------------------------------------------- assemble
const rewrite = (text) => REPLACEMENTS.reduce((value, [pattern, replacement]) => value.replace(pattern, replacement), text)

const cases = []

for (const item of local) {
  if (item.section) {
    const title = SECTION_TITLES[item.section] ?? item.section
    cases.push({ section: title })
    cases.push(...(ADDED_AFTER[`__section:${title}`] ?? []))
    continue
  }

  if (REMOVED.has(item.k)) continue

  const rewritten = {
    k: item.k,
    o: rewrite(item.o),
    p: rewrite(item.p),
    d: rewrite(item.d),
    s: item.s.map(rewrite),
    e: rewrite(item.e),
    ...(OVERRIDES[item.k] ?? {}),
  }

  cases.push(rewritten)
  cases.push(...(ADDED_AFTER[item.k] ?? []))
}

for (const key of Object.keys(OVERRIDES)) {
  if (!cases.some((item) => item.k === key)) throw new Error(`override for a case that is not in the edition: ${key}`)
}

for (const key of Object.keys(ADDED_AFTER)) {
  if (!key.startsWith('__section:') && !cases.some((item) => item.k === key)) throw new Error(`cases added after a case that is not in the edition: ${key}`)
  if (key.startsWith('__section:') && !cases.some((item) => item.section === key.slice(10))) throw new Error(`cases added to a section that is not in the edition: ${key}`)
}

module.exports = cases
