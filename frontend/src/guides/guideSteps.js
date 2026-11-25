// What each in-app guide says, by role and page (route name). Shown by
// ./useGuide.js.
//
// A step points at a part of the page with a CSS selector; a step without one
// is a note in the middle of the screen. A step whose part is not on screen
// (an empty list, a phone layout) is left out, so a guide never points at
// nothing. `desktopOnly` / `compactOnly` pick steps for wide screens or for
// phones and tablets (up to 900px, where the menu is a drawer).
//
// House style, as product tours usually go: 3 to 5 steps a page, a title of a
// few words, one or two short sentences saying what the part is FOR (not what
// it looks like), the person addressed as "you".
//
// When a page changes, check that its guide still points at what it says:
// renaming a class here quietly drops that step. Text is rendered as HTML, so
// it must never include anything a user typed.

const step = (element, title, description, extra = {}) => ({ element, popover: { title, description }, ...extra })
const note = (title, description, extra = {}) => ({ popover: { title, description }, ...extra })

const WELCOME_LINE = {
  student: 'Take mock board exams, see where you stand, and practise your weak topics.',
  teacher: 'Build exams from your question sets, run them in your rooms, and follow how each class is doing.',
  admin: 'Manage accounts and settings, and step in for any teacher: you can do everything they can.',
}

/** Shown before the first guide someone ever sees. */
export function welcomeSteps(role) {
  return [
    note('Welcome to BLIS', `${WELCOME_LINE[role] ?? WELCOME_LINE.student} This short tour shows you around.`),
    step('.layout-nav', 'Your pages', 'Move between the parts of BLIS here.', { desktopOnly: true }),
    note('Your pages', 'Open the menu at the top left to move between the parts of BLIS.', { compactOnly: true }),
    step('[data-guide-button]', 'Help on any page', 'Press this on any page to see its guide again.'),
  ]
}

// ------------------------------------------------------------------ students

const STUDENT = {
  'dashboard-home': [
    step('.home-hero', 'Your starting point', 'How you are doing, with shortcuts to practise your weakest area or open your Analytics.'),
    step('.notices-block', 'Exam notices', 'Exams that are open, coming up or closing soon. Check here so you never miss one.'),
    step('.outcome-card', 'Estimated Outcome', 'Your estimated chance of passing the LLE, once you finish all six subjects of a pre-test or mock board. Use it to plan your review.'),
  ],
  'dashboard-rooms': [
    step('.rooms-join-btn', 'Join a room', 'Enter the room code your teacher gives you to join their class.'),
    step('.rooms-list-card', 'Your rooms', 'Every class you have joined. Pick one to see its exams.'),
    step('.room-detail-column', 'Exams in this room', 'Each exam shows when it opens and closes. Press <b>Take Exam</b> once it is open.'),
    note('While taking an exam', 'Your answers save as you go. Use <b>Mark for Review</b> to come back to a question; before you submit, you see which ones you left blank.'),
  ],
  'dashboard-analytics': [
    step('.analytics-overview-card', 'Your overall level', 'Your average across every exam, your trend, and how far you are from the passing mark.'),
    step(
      '.analytics-tabs',
      'Five views',
      '<b>Subjects</b>: your score per board subject.<br><b>Topics</b>: what you have mastered.<br><b>Study Plan</b>: what to review first.<br><b>Exam History</b>: every exam you took.<br><b>Estimated Outcome</b>: your chance of passing.',
    ),
    step('#analytics-panel', 'The details', 'The view you pick appears here. Press an (i) icon to see what a figure means.'),
  ],
  'dashboard-review-bot': [
    step('.review-setup', 'Set up a practice set', 'Pick a subject and topic, or let BLIS choose your weak areas, then how many questions.'),
    step('.review-session', 'Practise', 'Each answer is checked right away and shows the correct one, so you learn as you go.'),
    note('Where the questions come from', 'They are built from exams you have taken, once the results are shown and you have no retake left. More exams, more practice.'),
  ],
}

// ------------------------------------------------------------------ teachers

const TEACHER = {
  'dashboard-reports': [
    step('.tdash-hero', 'Your dashboard', 'How your classes are doing at a glance. You land here after signing in.'),
    step('.stats-grid', 'Key numbers', 'Your rooms and exams, how many students have finished them, and the average score.'),
    step(
      '.tdash-tabs',
      'Four views',
      '<b>Overview</b>: score spread and trend.<br><b>Students &amp; Topics</b>: who needs help, and weak topics.<br><b>Exams</b>: results and the most-missed questions.<br><b>Exports</b>: PDF, Excel and CSV reports, or email them to students.',
    ),
    step('section[aria-labelledby="rp-actions-heading"]', 'What to do next', 'Suggestions for what needs your attention, most urgent first.'),
  ],
  'dashboard-library': [
    step('.library-hero-btn', 'Add questions', 'Upload a Word file of questions. BLIS finds the answers however you marked them, and tags each question by subject and topic.'),
    step('.library-search', 'Find a set', 'Search by name or file, or filter by subject.'),
    step('.library-table, .library-grid', 'Your question sets', 'Open a set to view or edit its questions. <b>Tags to confirm</b> means BLIS was unsure of a topic: check it so your analytics stay accurate.'),
  ],
  'dashboard-review-bot-generator': [
    step('.rbg-head', 'Practice questions', 'Turn the questions in your Library files into practice questions for the students\' Review Bot.'),
    step('.rbg-generate', 'Generate and publish', 'Pick a file and generate. Check the drafts, then publish the ones students should practise.'),
    note('Who can practise them', 'A student only gets questions from exams they took, once that exam\'s results are visible and they have no retake left.'),
  ],
  'dashboard-room-management': [
    step('.rooms-join-btn', 'Add a room', 'Make a room for each class. Students join it with the room\'s code.'),
    step('.rooms-list-card', 'Your rooms', 'Pick a room to manage it.'),
    step('.room-banner', 'Room details', 'The join code to share, and a switch to stop new students joining once your class is complete.'),
    step('.rooms-exams-card', 'Exams in this room', 'While an exam is running, open its <b>Live Board</b> to watch progress and give a student extra time.'),
    step('.rooms-roster-card', 'Class list', 'Everyone in the room. Click a name to open that student\'s profile.'),
  ],
  'dashboard-exams': [
    step('.examlist-hero-btn', 'New exam', 'Build an exam from your question sets: its rooms, schedule, number of items, and when students see the answers.'),
    step('.examlist-toolbar', 'Find an exam', 'Search, filter by status or room, and sort.'),
    step('.examlist-table', 'Your exams', 'Open one to edit or archive it. Once students start, the questions and timing lock, so everyone sits the same exam.'),
  ],
  'dashboard-students': [
    step('.user-directory-summary-card', 'Your students', 'How many students you teach, and how many are enrolled.'),
    step('.management-toolbar', 'Search and filter', 'Find a student by name or ID, or filter by year level and status.'),
    step('.directory-table', 'The list', 'Each student\'s average and latest grade. Click a name to see their full profile.'),
  ],
  'dashboard-student-profile': [
    step('.profile-hero', 'Student profile', 'One student\'s details and standing, for their teachers.'),
    step('.profile-outcome', 'Estimated Outcome', 'Their estimated chance of passing the LLE, from their latest complete pre-test or mock board.'),
    step('.profile-trend-chart', 'Score trend', 'Their last eight exams against the passing mark.'),
    step('.tm-subject', 'Topic mastery', 'The topics they have mastered and the ones that need work, updated after every exam.'),
  ],
  'dashboard-licensure': [
    step('.lle-mode', 'Record board results', 'When the PRC publishes the results, record them here for a whole batch or for one student.'),
    step('.lle-trend', 'Passing rate trend', 'LNU\'s passing rate by exam year, beside the national rate.'),
    step('.lle-model-facts', 'The prediction model', 'The Estimated Outcome model in use and how accurate it is. Recorded results help it improve.'),
    step('.lle-table', 'Recorded results', 'Every board result on record, by student.'),
  ],
  'dashboard-settings': [
    step('.account-card', 'Your account', 'Change your name, sign-in email and password.'),
    step('[data-guide="exam-defaults"]', 'Exam defaults', 'The settings every new exam starts with, so you do not set them each time.'),
    step('[data-guide="guides"]', 'Guides', 'Show every guide again from the start.'),
  ],
}

// ------------------------------------------------------------ administrators

// A teacher's page, plus a step about seeing every teacher's work.
const byTeacher = (routeName, filter) => [
  ...TEACHER[routeName],
  step(filter, 'By teacher', 'You see every teacher\'s work. Pick one teacher to see only theirs. Anything you change stays theirs, and is noted in the audit log.'),
]

const ADMIN = {
  'dashboard-reports': [
    ...TEACHER['dashboard-reports'].slice(0, 1),
    step('.tdash-hero-filter', 'By teacher', 'You see every teacher\'s classes. Pick one teacher to see only theirs.'),
    ...TEACHER['dashboard-reports'].slice(1),
  ],
  'dashboard-library': byTeacher('dashboard-library', '.owner-filter'),
  'dashboard-exams': byTeacher('dashboard-exams', '.owner-filter'),
  'dashboard-licensure': [
    ...TEACHER['dashboard-licensure'].slice(0, 2),
    step('.lle-model-facts', 'The prediction model', 'The Estimated Outcome model in use and how accurate it is. Once enough new results are recorded, <b>Retrain model</b> builds a new version; it is only used if it does better.'),
    ...TEACHER['dashboard-licensure'].slice(3),
  ],
  'dashboard-users': [
    step('.add-room-btn', 'Add a user', 'Create accounts for students, teachers and administrators. Accounts you create can sign in right away.'),
    step('.management-toolbar', 'Find someone', 'Search by name, email or student ID.'),
    step('.directory-section', 'The directory', 'Students by year, then archived students, teachers and administrators. Use <b>Verify</b> for someone who cannot open their email link.'),
    step('a[href*="year-end"]', 'End of school year', 'When the year ends, graduate fourth years and move everyone else up from here.'),
  ],
  'dashboard-year-end': [
    step('.year-end-summary', 'What closing the year does', 'Fourth years graduate and everyone else moves up one year.'),
    step('.year-end-group', 'Check the list', 'Untick anyone it should not apply to, such as a student who is repeating.'),
    step('.year-end-bar', 'Apply when ready', 'Nothing changes until you confirm here. It never happens by itself.'),
  ],
  'dashboard-audit': [
    step('.audit-filters', 'Filter the log', 'Narrow it down by what happened, who did it, and when.'),
    step('.audit-head-actions', 'Export', 'Download what you filtered as an Excel or CSV file.'),
    step('.audit-table', 'The log', 'Every sign-in and change, newest first. No one can edit or delete it.'),
  ],
  'dashboard-feedback': [
    step('.fb-tabs', 'Feedback inbox', 'What people sent from their Settings page, by status.'),
    step('.fb-toolbar', 'Handle it', 'Reply, close it, or pass it to the developers. Problem reports reach the developers by themselves.'),
  ],
  'dashboard-settings': [
    step('.account-card', 'Your account', 'Change your name, sign-in email and password.'),
    step('[data-guide="system-settings"]', 'System settings', 'School-wide settings: the semester calendar, the students\' passing mark, the announcement banner and more.'),
    step('[data-guide="guides"]', 'Guides', 'Show every guide again from the start.'),
  ],
}

const GUIDES = { student: STUDENT, teacher: TEACHER, admin: ADMIN }

/** A page's guide for a role, or null. An administrator gets the teachers' guide where there is no admin one. */
export function guideFor(role, routeName) {
  const own = GUIDES[role]?.[routeName]
  const steps = own ?? (role === 'admin' ? GUIDES.teacher[routeName] : undefined)
  return steps && steps.length ? { steps } : null
}
