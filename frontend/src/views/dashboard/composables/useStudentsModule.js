import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { formatDateTime } from './useDashboardFormatters'
import { useDashboardDataServices } from './useDashboardDataServices'
import { useLiveRefresh } from '@/utils/liveRefresh'

const PASSING_SCORE = 75

// Fixed page size -- deliberately not user-adjustable, so the table always
// paginates the same way for every staff member looking at the directory.
const STUDENTS_PAGE_SIZE = 30

// Long enough that typing a name does not fire a request per character, short
// enough that the table still feels like it is keeping up.
const SEARCH_DEBOUNCE_MS = 300

// Sorting, filtering and paging all happen in SQL now (see
// DashboardAnalyticsService::paginateStudentDirectory), so this list only has
// to agree with the server's whitelist -- an unknown key falls back to name
// there rather than erroring.
const SORT_KEYS = new Set([
  'name',
  'student_id',
  'year_level',
  'student_status',
  'admission_year',
  'room_count',
  'attempts_submitted',
  'average_score_percent',
  'pass_rate_percent',
  'latest_score_percent',
  'last_activity_at',
  'email',
])

// Numeric columns are most useful ranked high-to-low on the first click.
const DESCENDING_FIRST_KEYS = new Set([
  'room_count',
  'attempts_submitted',
  'average_score_percent',
  'pass_rate_percent',
  'latest_score_percent',
  'last_activity_at',
])

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function yearLevelLabel(value) {
  const numeric = Number(value)
  if (!Number.isFinite(numeric) || numeric < 1) return 'Unassigned year level'

  const suffixes = ['th', 'st', 'nd', 'rd']
  const suffix = suffixes[numeric] ?? 'th'
  return `${numeric}${suffix} Year`
}

function yearLevelShortLabel(value) {
  const numeric = Number(value)
  if (!Number.isFinite(numeric) || numeric < 1) return '--'

  const suffixes = ['th', 'st', 'nd', 'rd']
  const suffix = suffixes[numeric] ?? 'th'
  return `${numeric}${suffix}`
}

// Number(null) is 0, so blank values have to be rejected before coercion or
// "no attempts yet" renders as a real 0% score.
function numericOrNull(value) {
  if (value === null || value === undefined || value === '') return null

  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : null
}

function formatPercent(value, fallback = 'N/A') {
  const numeric = numericOrNull(value)
  if (numeric === null) return fallback

  return `${new Intl.NumberFormat(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
  }).format(numeric)}%`
}

function scoreTone(student) {
  if (Number(student?.attempts_submitted ?? 0) < 1) return 'neutral'
  return Number(student?.average_score_percent ?? 0) >= PASSING_SCORE ? 'success' : 'danger'
}

export function useStudentsModule() {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
  const isStaffDirectoryRole = computed(() => (
    ['admin', 'staff_master_examiner'].includes(normalizedRole.value)
  ))

  const directoryLoading = ref(false)
  const directoryError = ref('')
  const directorySummary = ref({})
  const directoryStudents = ref([])
  const directoryMeta = ref({
    current_page: 1,
    last_page: 1,
    per_page: STUDENTS_PAGE_SIZE,
    total: 0,
    from: 0,
    to: 0,
  })

  const studentFilters = reactive({
    search: '',
    year_level: '',
    student_status: '',
    admission_year: '',
    archive_state: 'current',
  })

  const studentStatusOptions = [
    { value: 'enrolled', label: 'Enrolled' },
    { value: 'graduated', label: 'Graduated' },
    { value: 'dropped', label: 'Dropped out' },
    { value: 'transferred', label: 'Transferred' },
    { value: 'on_leave', label: 'On leave' },
    { value: 'archived_unspecified', label: 'Reason not recorded' },
  ]

  function studentStatusLabel(value) {
    return studentStatusOptions.find((option) => option.value === value)?.label ?? '--'
  }

  const studentSort = reactive({
    key: 'name',
    direction: 'asc',
  })
  const studentsPage = ref(1)

  const yearLevelOptions = [
    { value: '1', label: '1st Year' },
    { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' },
    { value: '4', label: '4th Year' },
  ]

  function isArchivedStudent(student) {
    return Boolean(student?.archived_at)
  }

  // Responses can land out of order once the search is debounced -- a slow
  // early request would otherwise overwrite the results of a faster later one.
  // Only the newest request is allowed to write to the refs.
  let requestToken = 0
  let searchTimer = null

  // silent: the quiet refresh behind useLiveRefresh (below). Same request,
  // but no loading state, and a failure leaves the list as it was.
  async function loadStudentsDirectory({ silent = false } = {}) {
    if (!isStaffDirectoryRole.value) return

    const token = ++requestToken

    if (!silent) {
      directoryLoading.value = true
      directoryError.value = ''
    }

    try {
      const { data } = await services.getStudentsDirectory({
        search: studentFilters.search.trim() || undefined,
        year_level: studentFilters.year_level || undefined,
        student_status: studentFilters.student_status || undefined,
        admission_year: studentFilters.admission_year || undefined,
        archive_state: studentFilters.archive_state || undefined,
        sort: studentSort.key,
        direction: studentSort.direction,
        page: studentsPage.value,
        per_page: STUDENTS_PAGE_SIZE,
      })

      if (token !== requestToken) return

      directorySummary.value = data?.summary ?? {}
      directoryStudents.value = Array.isArray(data?.students) ? data.students : []
      directoryMeta.value = data?.meta ?? directoryMeta.value

      // The server clamps the page to the last one that exists, so a filter
      // that shrinks the result set under the current page corrects itself
      // rather than showing an empty table.
      if (directoryMeta.value.current_page && directoryMeta.value.current_page !== studentsPage.value) {
        studentsPage.value = directoryMeta.value.current_page
      }
    } catch (error) {
      if (token !== requestToken || silent) return
      directoryError.value = firstApiError(error, 'Unable to load the student directory right now.')
      directoryStudents.value = []
    } finally {
      if (token === requestToken && !silent) directoryLoading.value = false
    }
  }

  // Read again when someone comes back to this page: same filters, same
  // page, the figures as they are now.
  useLiveRefresh(() => loadStudentsDirectory({ silent: true }), {
    when: () => !directoryLoading.value,
  })

  const studentsPagination = computed(() => directoryMeta.value)

  const canGoToPreviousStudentsPage = computed(() => studentsPagination.value.current_page > 1)
  const canGoToNextStudentsPage = computed(() => (
    studentsPagination.value.current_page < studentsPagination.value.last_page
  ))

  const studentsRangeLabel = computed(() => {
    const { from, to, total } = studentsPagination.value
    if (!total || total < 1) return 'No students found'

    return `Showing ${from}-${to} of ${total} student${total === 1 ? '' : 's'}`
  })

  function goToStudentsPage(page) {
    const target = Number(page)
    if (!Number.isFinite(target)) return

    studentsPage.value = Math.min(
      Math.max(1, Math.trunc(target)),
      Math.max(1, studentsPagination.value.last_page),
    )
  }

  function toggleStudentSort(key) {
    if (!SORT_KEYS.has(key)) return

    if (studentSort.key === key) {
      studentSort.direction = studentSort.direction === 'asc' ? 'desc' : 'asc'
    } else {
      studentSort.key = key
      studentSort.direction = DESCENDING_FIRST_KEYS.has(key) ? 'desc' : 'asc'
    }

    studentsPage.value = 1
  }

  function sortIndicator(key) {
    if (studentSort.key !== key) return ''
    return studentSort.direction === 'asc' ? '▲' : '▼'
  }

  function sortAriaValue(key) {
    if (studentSort.key !== key) return 'none'
    return studentSort.direction === 'asc' ? 'ascending' : 'descending'
  }

  // Typing debounces; everything else is a deliberate click and refetches at
  // once. Sort changes reset the page in the same tick, so Vue batches them
  // into a single reload rather than two.
  watch(() => studentFilters.search, () => {
    studentsPage.value = 1
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => loadStudentsDirectory(), SEARCH_DEBOUNCE_MS)
  })

  watch(() => [
    studentFilters.year_level,
    studentFilters.student_status,
    studentFilters.admission_year,
    studentFilters.archive_state,
  ], () => {
    studentsPage.value = 1
    loadStudentsDirectory()
  })

  watch(() => [studentSort.key, studentSort.direction, studentsPage.value], () => {
    loadStudentsDirectory()
  })

  const studentDirectoryCards = computed(() => {
    const summary = directorySummary.value ?? {}

    return [
      {
        key: 'displayed',
        label: 'Matching Students',
        value: studentsPagination.value.total ?? 0,
        note: 'Across every page of the current filter',
        tone: 'navy',
      },
      {
        key: 'current',
        label: 'Current Students',
        value: summary.current_students ?? 0,
        note: `${summary.archived_students ?? 0} archived records available`,
        tone: 'success',
      },
      {
        key: 'average-score',
        label: 'Average Score',
        // Summary figures cover every current student, not just the page or
        // the active filter -- computing them per filter would mean a second
        // full rollup on every keystroke.
        value: formatPercent(summary.average_score_percent),
        note: `Across ${summary.students_with_results ?? 0} students with graded attempts`,
        tone: 'gold',
      },
      {
        key: 'average-pass-rate',
        label: 'Average Pass Rate',
        value: formatPercent(summary.average_pass_rate_percent),
        note: `Share of attempts at or above ${PASSING_SCORE}%`,
        tone: 'neutral',
      },
    ]
  })

  function roomNamesLabel(student) {
    const roomNames = Array.isArray(student?.room_names) ? student.room_names : []
    if (roomNames.length === 0) return 'No sections'
    return roomNames.join(', ')
  }

  function latestExamLabel(student) {
    return String(student?.latest_exam_title ?? '').trim()
  }

  function lastActivityLabel(student) {
    return student?.last_activity_at ? formatDateTime(student.last_activity_at) : 'No activity yet'
  }

  function strongestSubjectLabel(student) {
    return String(student?.strongest_subject ?? '').trim()
  }

  function archivedDateLabel(student) {
    if (!student?.archived_at) return ''
    return new Date(student.archived_at).toLocaleDateString()
  }

  onMounted(async () => {
    await loadStudentsDirectory()
  })

  onUnmounted(() => {
    if (searchTimer) clearTimeout(searchTimer)
  })

  return {
    directoryLoading,
    directoryError,
    studentFilters,
    yearLevelOptions,
    studentStatusOptions,
    studentStatusLabel,
    students: directoryStudents,
    studentSort,
    studentsPagination,
    studentsRangeLabel,
    canGoToPreviousStudentsPage,
    canGoToNextStudentsPage,
    goToStudentsPage,
    toggleStudentSort,
    sortIndicator,
    sortAriaValue,
    studentDirectoryCards,
    yearLevelLabel,
    yearLevelShortLabel,
    formatPercent,
    scoreTone,
    isArchivedStudent,
    roomNamesLabel,
    latestExamLabel,
    lastActivityLabel,
    strongestSubjectLabel,
    archivedDateLabel,
    loadStudentsDirectory,
  }
}
