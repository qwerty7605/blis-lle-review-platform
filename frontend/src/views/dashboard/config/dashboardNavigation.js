import {
  Award,
  BarChart3,
  BookOpen,
  ClipboardList,
  DoorOpen,
  FileText,
  GraduationCap,
  LayoutDashboard,
  Settings,
  Sparkles,
  UserRound,
  Users,
  Wand2,
} from 'lucide-vue-next'

export const dashboardModules = {
  dashboard: {
    key: 'dashboard',
    routeName: 'dashboard-home',
    label: 'Dashboard',
    title: 'Dashboard',
    sub: 'See your exam notices and where you stand',
    icon: LayoutDashboard,
  },
  rooms: {
    key: 'rooms',
    routeName: 'dashboard-rooms',
    label: 'Rooms',
    title: 'Rooms',
    sub: 'Join and track your assigned room memberships',
    icon: DoorOpen,
  },
  analytics: {
    key: 'analytics',
    routeName: 'dashboard-analytics',
    label: 'Analytics',
    title: 'Analytics',
    sub: 'Monitor trends and identify weak areas quickly',
    icon: BarChart3,
  },
  reviewBot: {
    key: 'reviewBot',
    routeName: 'dashboard-review-bot',
    label: 'Review Bot',
    title: 'Review Bot',
    sub: 'Study with a BLIS tutor bot across the 6 core subjects',
    icon: Sparkles,
  },
  examResult: {
    key: 'examResult',
    routeName: 'dashboard-exam-result',
    label: 'Exam Result',
    title: 'Exam Result',
    sub: 'Your score, breakdown, and recommendations for this attempt',
    icon: FileText,
    // Reached only by navigating from a specific exam attempt, never a
    // standing destination -- kept out of the sidebar, still an allowed
    // route for the student role (see getDashboardNavItems/dashboardAllowedRouteNames below).
    hidden: true,
  },
  room: {
    key: 'room',
    routeName: 'dashboard-room-management',
    label: 'Room',
    title: 'Rooms',
    sub: 'Create rooms, review enrollment, and track assigned exams',
    icon: DoorOpen,
  },
  library: {
    key: 'library',
    routeName: 'dashboard-library',
    label: 'Library',
    title: 'Library',
    sub: 'Manage exam content and question pools',
    icon: BookOpen,
  },
  reviewBotGenerator: {
    key: 'reviewBotGenerator',
    routeName: 'dashboard-review-bot-generator',
    label: 'Review Bot',
    title: 'Review Bot Question Generator',
    sub: 'Generate, review, and publish questions for the student Review Bot',
    icon: Wand2,
  },
  students: {
    key: 'students',
    routeName: 'dashboard-students',
    label: 'Students',
    title: 'Students',
    sub: 'Browse enrolled students and their performance history',
    icon: Users,
  },
  studentProfile: {
    key: 'studentProfile',
    routeName: 'dashboard-student-profile',
    label: 'Student Profile',
    title: 'Student Profile',
    sub: 'One student and how they are doing',
    icon: UserRound,
    // Reached by clicking a student in the directory, never from the sidebar.
    hidden: true,
  },
  licensure: {
    key: 'licensure',
    routeName: 'dashboard-licensure',
    label: 'Licensure',
    title: 'Licensure Results',
    sub: 'Record board exam outcomes for graduates',
    icon: Award,
  },
  exams: {
    key: 'exams',
    routeName: 'dashboard-exams',
    label: 'Exams',
    title: 'Exams',
    sub: 'Configure exam structures and schedules',
    icon: FileText,
  },
  reports: {
    key: 'reports',
    // The teacher dashboard: first in the sidebar, so it is also where staff
    // land after signing in. The key and route keep the name "reports".
    routeName: 'dashboard-reports',
    label: 'Dashboard',
    title: 'Dashboard',
    sub: 'How your classes are doing and what to do next',
    icon: LayoutDashboard,
  },
  settings: {
    key: 'settings',
    routeName: 'dashboard-settings',
    label: 'Settings',
    title: 'Settings',
    sub: 'Your name, sign-in details and password',
    icon: Settings,
  },
  users: {
    key: 'users',
    routeName: 'dashboard-users',
    label: 'Users',
    title: 'Users',
    sub: 'Create accounts, assign roles, and manage account status',
    icon: UserRound,
  },
  yearEnd: {
    key: 'yearEnd',
    routeName: 'dashboard-year-end',
    label: 'End of School Year',
    title: 'End of School Year',
    sub: 'Graduate the finishing class and move everyone else up a year',
    icon: GraduationCap,
    // Done once a year, so it is opened from the Users page rather than
    // holding a place in the sidebar.
    hidden: true,
  },
  audit: {
    key: 'audit',
    routeName: 'dashboard-audit',
    label: 'Audit',
    title: 'Audit Logs',
    sub: 'Track key system actions and account activity',
    icon: ClipboardList,
  },
}

const dashboardNavKeysByRole = {
  student: ['dashboard', 'rooms', 'reviewBot', 'analytics', 'examResult', 'settings'],
  staff_master_examiner: ['reports', 'library', 'reviewBotGenerator', 'room', 'students', 'studentProfile', 'licensure', 'exams', 'settings'],
  faculty: ['reports', 'library', 'reviewBotGenerator', 'room', 'students', 'studentProfile', 'licensure', 'exams', 'settings'],
  // Administration first, so signing in still lands on Users; then
  // everything a teacher has, over every teacher's work.
  admin: [
    'users', 'yearEnd', 'audit', 'settings',
    'reports', 'library', 'reviewBotGenerator', 'room', 'students', 'studentProfile', 'licensure', 'exams',
  ],
}

// Headings in the administrator's sidebar, which is the only one long enough
// to need them: each names the group that starts at that item.
const dashboardNavGroupStarts = {
  admin: { users: 'Administration', reports: 'Teaching' },
}

export function normalizeDashboardRole(role) {
  const normalizedRole = String(role ?? 'student').toLowerCase()
  return dashboardNavKeysByRole[normalizedRole] ? normalizedRole : 'student'
}

export function getDashboardModuleMeta(key) {
  return dashboardModules[key] ?? dashboardModules.dashboard
}

// Sidebar-visible items only -- a hidden module (e.g. examResult, reached by
// navigating from a specific attempt rather than a standing destination)
// is still a real, allowed route (see dashboardAllowedRouteNames), just
// never rendered as a nav link.
export function getDashboardNavItems(role) {
  const normalizedRole = normalizeDashboardRole(role)
  return dashboardNavKeysByRole[normalizedRole]
    .map((key) => ({
      ...getDashboardModuleMeta(key),
      groupLabel: dashboardNavGroupStarts[normalizedRole]?.[key] ?? null,
    }))
    .filter((item) => !item.hidden)
}

export function dashboardAllowedRouteNames(role) {
  const normalizedRole = normalizeDashboardRole(role)
  return dashboardNavKeysByRole[normalizedRole].map((key) => getDashboardModuleMeta(key).routeName)
}

export function dashboardDefaultRouteName(role) {
  return getDashboardNavItems(role)[0]?.routeName ?? dashboardModules.dashboard.routeName
}
