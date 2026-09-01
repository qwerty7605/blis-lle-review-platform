import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { formatDateTime } from './useDashboardFormatters'
import { useDashboardDataServices } from './useDashboardDataServices'

const PASSING_SCORE = 75
const STUDENTS_PAGE_SIZE_OPTIONS = [25, 50, 100]
const STUDENTS_DEFAULT_PAGE_SIZE = STUDENTS_PAGE_SIZE_OPTIONS[0]

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function yearLevelLabel(value) {
  const numeric = Number(value)
  if (numeric === 1) return '1st Year'
  if (numeric === 2) return '2nd Year'
  if (numeric === 3) return '3rd Year'
  if (numeric === 4) return '4th Year'
  return 'Year level not set'
}

function yearLevelShortLabel(value) {
  const numeric = Number(value)
  if ([1, 2, 3, 4].includes(numeric)) return yearLevelLabel(numeric).replace(' Year', '')
  return 'Not set'
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
    maximumFractionDigits: 2,
  }).format(numeric)}%`
}

function scoreTone(student) {
  if (Number(student?.attempts_submitted ?? 0) < 1) return 'neutral'
  return Number(student?.average_score_percent ?? 0) >= PASSING_SCORE ? 'success' : 'danger'
}

function compareStudents(left, right) {
  const nameCompare = String(left?.name ?? '').localeCompare(String(right?.name ?? ''), undefined, {
    sensitivity: 'base',
  })

  if (nameCompare !== 0) return nameCompare

  return String(left?.student_id ?? '').localeCompare(String(right?.student_id ?? ''), undefined, {
    numeric: true,
    sensitivity: 'base',
  })
}

function timestampOrNull(value) {
  if (!value) return null
  const timestamp = new Date(value).getTime()
  return Number.isFinite(timestamp) ? timestamp : null
}

// Every sortable column maps to the value the comparator should read.
const SORT_ACCESSORS = {
  name: (student) => String(student?.name ?? ''),
  student_id: (student) => student?.student_id ?? null,
  year_level: (student) => numericOrNull(student?.year_level),
  room_count: (student) => Number(student?.room_count ?? 0),
  attempts_submitted: (student) => Number(student?.attempts_submitted ?? 0),
  average_score_percent: (student) => numericOrNull(student?.average_score_percent),
  pass_rate_percent: (student) => numericOrNull(student?.pass_rate_percent),
  latest_score_percent: (student) => numericOrNull(student?.latest_score_percent),
  last_activity_at: (student) => timestampOrNull(student?.last_activity_at),
  email: (student) => String(student?.email ?? ''),
}

// Numeric columns are most useful ranked high-to-low on the first click.
const DESCENDING_FIRST_KEYS = new Set([
  'room_count',
  'attempts_submitted',
  'average_score_percent',
  'pass_rate_percent',
  'latest_score_percent',
  'last_activity_at',
])

function isEmptyValue(value) {
  return value === null || value === undefined || value === ''
}

// Empty values always sink to the bottom, whichever direction is active.
function compareEmptiness(left, right) {
  const leftEmpty = isEmptyValue(left)
  const rightEmpty = isEmptyValue(right)

  if (leftEmpty && rightEmpty) return 0
  if (leftEmpty) return 1
  if (rightEmpty) return -1
  return 0
}

function compareValues(left, right) {
  if (typeof left === 'number' && typeof right === 'number') return left - right

  return String(left).localeCompare(String(right), undefined, {
    numeric: true,
    sensitivity: 'base',
  })
}

export function useStudentsModule() {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
  const isStaffDirectoryRole = computed(() => (
    ['admin', 'staff_master_examiner', 'faculty'].includes(normalizedRole.value)
  ))

  const directoryLoading = ref(false)
  const directoryError = ref('')
  const directorySummary = ref({})
  const directoryStudents = ref([])
  const studentFilters = reactive({
    search: '',
    year_level: '',
    archive_state: 'current',
  })

  const studentSort = reactive({
    key: 'name',
    direction: 'asc',
  })
  const studentsPage = ref(1)
  const studentsPageSize = ref(STUDENTS_DEFAULT_PAGE_SIZE)

  const yearLevelOptions = [
    { value: '1', label: '1st Year' },
    { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' },
    { value: '4', label: '4th Year' },
  ]

  function isArchivedStudent(student) {
    return Boolean(student?.archived_at)
  }

  async function loadStudentsDirectory() {
    if (!isStaffDirectoryRole.value) return

    directoryLoading.value = true
    directoryError.value = ''

    try {
      const { data } = await services.getStudentsDirectory()
      directorySummary.value = data?.summary ?? {}
      directoryStudents.value = Array.isArray(data?.students) ? data.students : []
    } catch (error) {
      directoryError.value = firstApiError(error, 'Unable to load the student directory right now.')
    } finally {
      directoryLoading.value = false
    }
  }

  const filteredStudents = computed(() => {
    const search = studentFilters.search.trim().toLowerCase()

    return directoryStudents.value.filter((student) => {
      if (studentFilters.archive_state === 'current' && isArchivedStudent(student)) return false
      if (studentFilters.archive_state === 'archived' && !isArchivedStudent(student)) return false
      if (studentFilters.year_level && String(student?.year_level ?? '') !== studentFilters.year_level) return false

      if (!search) return true

      const haystack = [
        student?.name,
        student?.email,
        student?.student_id,
        ...(Array.isArray(student?.room_names) ? student.room_names : []),
        student?.latest_exam_title,
        student?.strongest_subject,
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()

      return haystack.includes(search)
    })
  })

  const sortedStudents = computed(() => {
    const accessor = SORT_ACCESSORS[studentSort.key] ?? SORT_ACCESSORS.name
    const factor = studentSort.direction === 'desc' ? -1 : 1

    return [...filteredStudents.value].sort((left, right) => {
      const leftValue = accessor(left)
      const rightValue = accessor(right)

      const emptiness = compareEmptiness(leftValue, rightValue)
      if (emptiness !== 0) return emptiness

      const result = compareValues(leftValue, rightValue)
      if (result !== 0) return result * factor

      return compareStudents(left, right)
    })
  })

  const studentsLastPage = computed(() => (
    Math.max(1, Math.ceil(sortedStudents.value.length / studentsPageSize.value))
  ))

  // Clamping here keeps the view valid when a filter shrinks the result set
  // underneath the page the user is currently on.
  const studentsCurrentPage = computed(() => Math.min(studentsPage.value, studentsLastPage.value))

  const pagedStudents = computed(() => {
    const start = (studentsCurrentPage.value - 1) * studentsPageSize.value
    return sortedStudents.value.slice(start, start + studentsPageSize.value)
  })

  const studentsPagination = computed(() => {
    const total = sortedStudents.value.length
    const perPage = studentsPageSize.value
    const currentPage = studentsCurrentPage.value

    return {
      current_page: currentPage,
      last_page: studentsLastPage.value,
      per_page: perPage,
      total,
      from: total === 0 ? 0 : ((currentPage - 1) * perPage) + 1,
      to: Math.min(currentPage * perPage, total),
    }
  })

  const canGoToPreviousStudentsPage = computed(() => studentsPagination.value.current_page > 1)
  const canGoToNextStudentsPage = computed(() => (
    studentsPagination.value.current_page < studentsPagination.value.last_page
  ))

  const studentsRangeLabel = computed(() => {
    const { from, to, total } = studentsPagination.value
    if (total < 1) return 'No students found'

    return `Showing ${from}-${to} of ${total} student${total === 1 ? '' : 's'}`
  })

  function goToStudentsPage(page) {
    const target = Number(page)
    if (!Number.isFinite(target)) return

    studentsPage.value = Math.min(Math.max(1, Math.trunc(target)), studentsLastPage.value)
  }

  function toggleStudentSort(key) {
    if (!SORT_ACCESSORS[key]) return

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

  watch(
    () => [studentFilters.search, studentFilters.year_level, studentFilters.archive_state, studentsPageSize.value],
    () => {
      studentsPage.value = 1
    },
  )

  const studentsWithResults = computed(() => (
    filteredStudents.value.filter((student) => Number(student?.attempts_submitted ?? 0) > 0)
  ))

  const studentDirectoryCards = computed(() => {
    const scoreValues = studentsWithResults.value
      .map((student) => Number(student?.average_score_percent))
      .filter((score) => Number.isFinite(score))
    const passRateValues = studentsWithResults.value
      .map((student) => Number(student?.pass_rate_percent))
      .filter((score) => Number.isFinite(score))

    return [
      {
        key: 'displayed',
        label: 'Displayed Students',
        value: filteredStudents.value.length,
        note: `${studentsWithResults.value.length} with submitted results`,
        tone: 'navy',
      },
      {
        key: 'current',
        label: 'Current Students',
        value: filteredStudents.value.filter((student) => !isArchivedStudent(student)).length,
        note: `${directorySummary.value?.current_students ?? 0} in the full directory`,
        tone: 'success',
      },
      {
        key: 'average-score',
        label: 'Average Score',
        value: formatPercent(scoreValues.length > 0 ? scoreValues.reduce((sum, score) => sum + score, 0) / scoreValues.length : null),
        note: 'Across students with graded attempts',
        tone: 'gold',
      },
      {
        key: 'average-pass-rate',
        label: 'Average Pass Rate',
        value: formatPercent(passRateValues.length > 0 ? passRateValues.reduce((sum, score) => sum + score, 0) / passRateValues.length : null),
        note: `${directorySummary.value?.archived_students ?? 0} archived records available`,
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

  return {
    directoryLoading,
    directoryError,
    studentFilters,
    yearLevelOptions,
    filteredStudents,
    pagedStudents,
    studentSort,
    studentsPagination,
    studentsPageSize,
    studentsPageSizeOptions: STUDENTS_PAGE_SIZE_OPTIONS,
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
