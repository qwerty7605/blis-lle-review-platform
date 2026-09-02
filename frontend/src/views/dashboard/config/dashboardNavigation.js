import {
  BarChart3,
  BookOpen,
  ClipboardList,
  DoorOpen,
  FileText,
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
    sub: 'See recent exams, active notices, and your current rooms in one place',
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
    routeName: 'dashboard-reports',
    label: 'Reports',
    title: 'Reports',
    sub: 'Review aggregate and student-level insights',
    icon: BarChart3,
  },
  settings: {
    key: 'settings',
    routeName: 'dashboard-settings',
    label: 'Settings',
    title: 'Settings',
    sub: 'Manage preferences and account behavior',
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
  student: ['dashboard', 'rooms', 'reviewBot', 'analytics', 'examResult'],
  staff_master_examiner: ['library', 'reviewBotGenerator', 'room', 'students', 'exams', 'reports', 'settings'],
  faculty: ['library', 'reviewBotGenerator', 'room', 'students', 'exams', 'reports', 'settings'],
  admin: ['users', 'room', 'students', 'settings', 'audit'],
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
    .map((key) => getDashboardModuleMeta(key))
    .filter((item) => !item.hidden)
}

export function dashboardAllowedRouteNames(role) {
  const normalizedRole = normalizeDashboardRole(role)
  return dashboardNavKeysByRole[normalizedRole].map((key) => getDashboardModuleMeta(key).routeName)
}

export function dashboardDefaultRouteName(role) {
  return getDashboardNavItems(role)[0]?.routeName ?? dashboardModules.dashboard.routeName
}
