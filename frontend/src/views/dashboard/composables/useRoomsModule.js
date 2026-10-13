import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import {
  formatClockTime,
  normalizeExamDeliveryMode,
} from './useDashboardFormatters'
import {
  archivedExamAction,
  archivedExamActionLabel,
  archivedExamStatusText,
  canStudentOpenExam,
  isStudentExamCompleted,
  isStudentExamInProgress,
  isStudentExamRetakeLimitReached,
  studentExamActionLabel,
  studentExamAvailabilityText,
  studentMaxAttempts,
  studentSubmittedAttemptId,
} from './studentExamAvailability'
import { useDashboardDataServices } from './useDashboardDataServices'
import { useLiveRefresh } from '@/utils/liveRefresh'

// The width at which the exam screen stacks into one column and the question
// list becomes a bottom sheet. It has to match the exam layer's Responsive
// block in dashboard.css: this said 1200px while the stylesheet stacked at
// 900px, and in between the two disagreed about which column was which.
const EXAM_PHONE_VIEWPORT_QUERY = '(max-width: 900px)'

// How often an open exam page asks the server about its attempt. Answers are
// saved the moment they are picked and the countdown runs in the browser, so
// this only has to notice what changes on the server -- above all the attempt
// being closed. At 4 seconds it was most of the server's work during an exam:
// every student downloading the whole attempt again fifteen times a minute.
const EXAM_STATUS_CHECK_INTERVAL_MS = 30000

// How many times, a second apart, the page asks whether the exam has closed
// once its countdown runs out. The first answer is nearly always yes.
const EXAM_TIME_UP_TRIES = 10

// How often an open Rooms page asks the server what has changed in the room
// on screen: an exam assigned or rescheduled, a student joined. This is the
// page a class sits on while it waits for an exam, so the number is also how
// much the whole room asks of the server while waiting: at a minute, about
// four requests a second for 230 students.
const ROOM_LIVE_REFRESH_MS = 60000

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function extractDownloadFilename(contentDisposition, fallbackName) {
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

function saveDownloadedBlob(response, fallbackName) {
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

async function resolveBlobApiError(error, fallbackMessage) {
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

function displayMemberRole(role) {
  const normalized = String(role ?? '').toLowerCase()
  if (normalized === 'admin') return 'Administrator'
  if (normalized === 'staff_master_examiner') return 'Staff / Master Examiner'
  return 'Student'
}

function yearLevelLabel(value) {
  const numeric = Number(value)
  if (numeric === 1) return '1st Year'
  if (numeric === 2) return '2nd Year'
  if (numeric === 3) return '3rd Year'
  if (numeric === 4) return '4th Year'
  return 'Unassigned Year'
}

function compareRoomMembers(left, right) {
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

function sortRoomMembers(members) {
  return [...members].sort(compareRoomMembers)
}

function buildRoomYearSummary(members) {
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

function splitQuestionStemAndNumberedItems(questionText) {
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

export function useRoomsModule({ mode = 'student' } = {}) {
  const auth = useAuthStore()
  const services = useDashboardDataServices()
  const route = useRoute()
  const router = useRouter()

  const modeKey = String(mode ?? 'student').toLowerCase()
  const isManagementView = computed(() => modeKey === 'management')

  const roomName = ref('')
  const joinCode = ref('')
  const roomSearchQuery = ref('')
  const rooms = ref([])
  const selectedRoomId = ref(null)
  const selectedRoom = ref(null)
  const showCreateRoomModal = ref(false)
  const showJoinRoomModal = ref(false)
  const showEditRoomModal = ref(false)
  const showDeleteRoomModal = ref(false)
  const showArchiveRoomModal = ref(false)
  const showArchivedRoomsModal = ref(false)
  // Rooms put away with their results; a teacher's or administrator's only.
  const archivedRooms = ref([])
  const restoringRoomId = ref(null)
  const showRoomExportModal = ref(false)
  const showLeaveRoomModal = ref(false)
  const editRoomName = ref('')
  const roomLoading = ref(false)
  const roomDetailsLoading = ref(false)
  const roomExporting = ref(false)
  const roomError = ref('')
  const roomMessage = ref('')
  const roomExportFormats = [
    { value: 'xlsx', label: 'XLSX' },
    { value: 'pdf', label: 'PDF' },
    { value: 'csv', label: 'CSV' },
  ]
  const roomExportForm = reactive({
    format: 'xlsx',
  })

  const roomLiveBoardActive = ref(false)
  const liveBoardExam = ref(null)
  const liveBoardRoomId = ref(null)
  const liveBoardRows = ref([])
  const liveBoardItemSummary = ref([])
  const liveBoardSummary = ref({
    students_total: 0,
    attempts_started: 0,
    attempts_submitted: 0,
  })
  const liveBoardLoading = ref(false)
  const liveBoardRefreshing = ref(false)
  const liveBoardError = ref('')
  const liveBoardUpdatedAt = ref(null)
  const liveBoardOptions = reactive({
    show_names: true,
    show_responses: true,
    show_results: true,
  })

  const showExamSimulationModal = ref(false)
  const showStudentSubmitConfirmModal = ref(false)
  const showStudentExitConfirmModal = ref(false)
  const selectedStudentExam = ref(null)
  const studentExamAttempt = ref(null)
  const studentExamFeedback = ref(null)
  const studentExamReviewVisible = ref(false)
  const studentExamQuestions = ref([])
  const studentExamCurrentIndex = ref(0)
  const isExamMobileViewport = ref(false)
  const examAttemptSidebarCollapsed = ref(false)
  const studentExamLoading = ref(false)
  const studentExamSaving = ref(false)
  const studentExamBookmarking = ref(false)
  const studentExamSubmitting = ref(false)
  const studentExamError = ref('')
  const studentExamRemainingSeconds = ref(null)
  const studentExamVisitedQuestionIds = ref([])
  const studentAnswerDraft = reactive({
    selected_option_id: null,
    answer_text: '',
  })

  let examAttemptMobileMediaQuery
  let studentExamTimerInterval
  let studentExamSyncInterval
  let studentExamTimeUpCheck
  let liveBoardRefreshInterval
  let studentExamSyncing = false

  const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
  const isAdminRole = computed(() => normalizedRole.value === 'admin')
  const isStaffRole = computed(() => ['staff_master_examiner', 'faculty'].includes(normalizedRole.value))
  const isManagementRole = computed(() => isAdminRole.value || isStaffRole.value)
  // Teachers, and administrators, who can do everything a teacher can.
  const canCreateRooms = computed(() => isManagementRole.value)
  // A room's exams and their Live Board: the teacher's own rooms, and every
  // room for an administrator. Setting an exam up stays with its teacher.
  const canViewExamResults = computed(() => isManagementRole.value)

  const roomCollectionLabel = computed(() => {
    if (isAdminRole.value) return 'All rooms in the platform'
    if (isManagementRole.value) return "Rooms you've added"
    return 'Rooms joined'
  })

  const selectedRoomActiveExams = computed(() => (
    Array.isArray(selectedRoom.value?.assigned_exams) ? selectedRoom.value.assigned_exams : []
  ))

  const selectedRoomArchivedExams = computed(() => (
    Array.isArray(selectedRoom.value?.archived_exams) ? selectedRoom.value.archived_exams : []
  ))

  const selectedRoomCurrentMembers = computed(() => (
    sortRoomMembers(Array.isArray(selectedRoom.value?.members) ? selectedRoom.value.members : [])
  ))

  const selectedRoomYearSummary = computed(() => (
    buildRoomYearSummary(selectedRoomCurrentMembers.value)
  ))

  // Client-side filter over the room list the student already has (no new
  // request) -- matches on name or code, same as how a room is found by code
  // when joining.
  const filteredRooms = computed(() => {
    const query = roomSearchQuery.value.trim().toLowerCase()
    if (!query) return rooms.value

    return rooms.value.filter((room) => (
      String(room.name ?? '').toLowerCase().includes(query)
      || String(room.code ?? '').toLowerCase().includes(query)
    ))
  })

  // A real "how much of this room have I finished" number -- every assigned
  // exam (current + archived) counts once, and "completed" reuses the exact
  // same submitted-attempt check the exam cards use for their own state, so
  // this can never disagree with what's shown below it.
  const selectedRoomExamSummary = computed(() => {
    const allExams = [...selectedRoomActiveExams.value, ...selectedRoomArchivedExams.value]
    const totalCount = allExams.length
    const completedCount = allExams.filter((exam) => isStudentExamCompleted(exam)).length

    return {
      totalCount,
      completedCount,
      completionPercent: totalCount === 0 ? 0 : Math.round((completedCount / totalCount) * 100),
    }
  })

  const selectedRoomRosterCopy = computed(() => {
    if (!selectedRoom.value) return ''

    if (selectedRoomCurrentMembers.value.length === 0) {
      return 'No current students are enrolled in this room yet.'
    }

    if (selectedRoomYearSummary.value.is_mixed) {
      return 'This class list currently includes more than one year level.'
    }

    if (selectedRoomYearSummary.value.primary_year_level) {
      return `This room is currently organized as a ${selectedRoomYearSummary.value.label} section.`
    }

    return 'Year level tags are not set for this room yet.'
  })

  const currentStudentExamQuestion = computed(() => (
    studentExamQuestions.value[studentExamCurrentIndex.value] ?? null
  ))

  const currentQuestionStem = computed(() => (
    splitQuestionStemAndNumberedItems(currentStudentExamQuestion.value?.question_text ?? '')
  ))

  const isStudentExamSubmitted = computed(() => (
    String(studentExamAttempt.value?.status ?? '') === 'submitted'
  ))

  const isStudentExamResultSummaryVisible = computed(() => (
    isStudentExamSubmitted.value && !studentExamReviewVisible.value
  ))

  const isStudentOpenNavigationMode = computed(() => (
    !isStudentExamSubmitted.value
  ))

  const isCurrentQuestionInputLocked = computed(() => (
    isStudentExamSubmitted.value
      || studentExamSaving.value
      || studentExamSubmitting.value
      || studentExamBookmarking.value
  ))

  const studentExamUnansweredCount = computed(() => (
    studentExamQuestions.value.filter((question) => !questionHasAnswer(question)).length
  ))

  // The aggregate result (score, subject/topic breakdown, recommendations,
  // and the "Review Answers"/"Review Responses" action that used to live next
  // to it) has its own standalone page now (StudentExamResultView.vue,
  // fetched fresh from GET /student/exam-attempts/:id) instead of living
  // inside this overlay — see the redirect watcher below.

  // Whenever the aggregate result becomes the thing to show -- a fresh
  // submission, an auto-sync tick discovering the attempt got auto-submitted
  // (e.g. time expired), or a start-exam call resolving to an already-
  // submitted attempt -- hand off to the standalone result page instead of
  // rendering it in this overlay. One watcher covers every path uniformly
  // instead of threading a redirect through each call site individually.
  watch(isStudentExamResultSummaryVisible, (visible) => {
    if (!visible) return

    const attemptId = studentExamAttempt.value?.id
    if (!attemptId) return

    closeExamSimulationModal()
    router.push({ name: 'dashboard-exam-result', params: { attemptId } })
  })

  const liveBoardRoom = computed(() => {
    const roomId = Number(liveBoardRoomId.value)
    if (!Number.isFinite(roomId) || roomId < 1) return null

    if (Number(selectedRoom.value?.id) === roomId) {
      return selectedRoom.value
    }

    return rooms.value.find((room) => Number(room.id) === roomId) ?? null
  })

  const liveBoardLastUpdatedText = computed(() => (
    liveBoardUpdatedAt.value ? formatClockTime(liveBoardUpdatedAt.value) : 'n/a'
  ))

  watch(
    () => showExamSimulationModal.value,
    (isOpen) => {
      if (!isOpen) return

      const mobileViewport = isExamMobileViewport.value
        || (typeof window !== 'undefined' && window.matchMedia(EXAM_PHONE_VIEWPORT_QUERY).matches)

      isExamMobileViewport.value = mobileViewport
      examAttemptSidebarCollapsed.value = mobileViewport
    },
  )

  function syncExamAttemptSidebarForViewport(eventOrQuery) {
    const mobileViewport = Boolean(eventOrQuery?.matches)
    isExamMobileViewport.value = mobileViewport

    if (mobileViewport) {
      examAttemptSidebarCollapsed.value = true
      return
    }

    examAttemptSidebarCollapsed.value = false
  }

  function toggleExamAttemptSidebar() {
    examAttemptSidebarCollapsed.value = !examAttemptSidebarCollapsed.value
  }

  // The phone sheet reports open/closed; the flag is kept the other way
  // round because the desktop column, which collapses, came first.
  function setExamQuestionListOpen(open) {
    examAttemptSidebarCollapsed.value = !open
  }

  // On a phone the list is a sheet over the question, so picking a number
  // closes it -- otherwise the question just jumped to stays hidden behind
  // the list that was used to reach it.
  function pickStudentExamQuestionFromList(targetIndex) {
    goToStudentExamQuestionIndex(targetIndex)

    if (isExamMobileViewport.value) {
      examAttemptSidebarCollapsed.value = true
    }
  }

  // Deep link back from the exam result page's "Review Answers" button
  // (?room=&exam=&attempt=&review=1) -- opens straight into per-question
  // review of that attempt instead of leaving the student to find it in the
  // room's list.
  async function openReviewDeepLinkIfRequested() {
    if (String(route.query?.review ?? '') !== '1') return

    const targetExamId = Number(route.query?.exam)
    if (!Number.isFinite(targetExamId) || targetExamId < 1) return

    const targetExam = [
      ...selectedRoomActiveExams.value,
      ...selectedRoomArchivedExams.value,
    ].find((exam) => Number(exam.id) === targetExamId)

    if (!targetExam) return

    // The result page names the attempt it was showing. A link without one
    // falls back to the attempt the room lists, and only when that attempt
    // is finished: an attempt still in progress has nothing to review.
    const linkedAttemptId = Number(route.query?.attempt)
    const attemptId = Number.isFinite(linkedAttemptId) && linkedAttemptId > 0
      ? linkedAttemptId
      : (isStudentExamCompleted(targetExam) ? studentSubmittedAttemptId(targetExam) : null)

    if (!attemptId) return

    await openSubmittedAttemptReview(targetExam, attemptId)
    openStudentExamReview()
  }

  onMounted(async () => {
    if (typeof window !== 'undefined') {
      examAttemptMobileMediaQuery = window.matchMedia(EXAM_PHONE_VIEWPORT_QUERY)
      syncExamAttemptSidebarForViewport(examAttemptMobileMediaQuery)

      if (typeof examAttemptMobileMediaQuery.addEventListener === 'function') {
        examAttemptMobileMediaQuery.addEventListener('change', syncExamAttemptSidebarForViewport)
      } else {
        examAttemptMobileMediaQuery.addListener(syncExamAttemptSidebarForViewport)
      }
    }

    // Deep link from the home dashboard's "Resume/Take/Retake/Upcoming"
    // action — preselect that room instead of defaulting to the first one.
    const requestedRoomId = Number(route.query?.room)
    await fetchRooms(Number.isFinite(requestedRoomId) && requestedRoomId > 0 ? requestedRoomId : null)
    loadArchivedRooms()
    await openReviewDeepLinkIfRequested()
  })

  /*
   * ?room= is also how the phone nav drawer switches rooms, and that happens
   * while this view is already mounted -- onMounted has long since run. Without
   * this watcher the URL changed and the page kept showing the previous room.
   */
  watch(
    () => route.query?.room,
    async (rawRoomId) => {
      const roomId = Number(rawRoomId)
      if (!Number.isFinite(roomId) || roomId < 1) return
      if (roomId === selectedRoomId.value) return
      if (!rooms.value.some((room) => room.id === roomId)) return

      await selectRoom(roomId)
    },
  )

  onBeforeUnmount(() => {
    clearStudentExamTimer()
    stopStudentExamAutoSync()
    stopLiveBoardAutoRefresh()

    if (!examAttemptMobileMediaQuery) return

    if (typeof examAttemptMobileMediaQuery.removeEventListener === 'function') {
      examAttemptMobileMediaQuery.removeEventListener('change', syncExamAttemptSidebarForViewport)
    } else {
      examAttemptMobileMediaQuery.removeListener(syncExamAttemptSidebarForViewport)
    }
  })

  function canRemoveRoomMember(member) {
    if (!isManagementRole.value) return false

    const memberRole = String(member?.role ?? '').toLowerCase()
    return memberRole === 'student'
  }

  function clearStudentExamTimer() {
    clearTimeout(studentExamTimeUpCheck)
    studentExamTimeUpCheck = null

    if (!studentExamTimerInterval) return
    clearInterval(studentExamTimerInterval)
    studentExamTimerInterval = null
  }

  function startStudentExamTimer(remainingSeconds) {
    clearStudentExamTimer()

    if (!Number.isFinite(Number(remainingSeconds))) {
      studentExamRemainingSeconds.value = null
      return
    }

    // The server sends fractions of a second. Counted down as they came, the
    // value stepped from 0.4 to -0.6 and never read zero, which is the moment
    // the watcher at the bottom of this file waits for. Rounded up rather than
    // down, so the page cannot reach zero before the server does.
    studentExamRemainingSeconds.value = Math.max(0, Math.ceil(Number(remainingSeconds)))

    if (studentExamRemainingSeconds.value <= 0 || isStudentExamSubmitted.value) return

    studentExamTimerInterval = setInterval(() => {
      if (studentExamRemainingSeconds.value === null || studentExamRemainingSeconds.value <= 0) {
        clearStudentExamTimer()
        return
      }

      studentExamRemainingSeconds.value -= 1
    }, 1000)
  }

  function stopStudentExamAutoSync() {
    if (!studentExamSyncInterval) return
    clearInterval(studentExamSyncInterval)
    studentExamSyncInterval = null
    studentExamSyncing = false
  }

  async function refreshStudentExamAttemptStatus(silent = true) {
    if (!showExamSimulationModal.value || isStudentExamSubmitted.value) return
    if (studentExamLoading.value || studentExamSaving.value || studentExamSubmitting.value || studentExamBookmarking.value) return
    if (studentExamSyncing) return

    const attemptId = studentExamAttempt.value?.id
    if (!attemptId) return

    const currentQuestionId = currentStudentExamQuestion.value?.question_id ?? null
    studentExamSyncing = true

    try {
      const { data } = await services.getAttempt(attemptId)
      applyStudentAttemptPayload(data, currentQuestionId)
    } catch (error) {
      if (!silent) {
        studentExamError.value = firstApiError(error, 'Unable to refresh attempt status.')
      }
    } finally {
      studentExamSyncing = false
    }
  }

  function startStudentExamAutoSync() {
    stopStudentExamAutoSync()

    studentExamSyncInterval = setInterval(() => {
      refreshStudentExamAttemptStatus(true)
    }, EXAM_STATUS_CHECK_INTERVAL_MS)
  }

  /*
   * The countdown only counts. An attempt is closed by the server, the next
   * time it is asked about one whose time has passed -- so the page asks the
   * moment its countdown runs out, rather than sit at 00:00 until the next
   * periodic check.
   *
   * One question is not always enough: refreshStudentExamAttemptStatus()
   * stands aside while an answer is being saved, and the two clocks can
   * disagree by a moment. So it asks again, a second apart, until the page has
   * its answer or has waited long enough to leave it to the periodic check.
   */
  function confirmStudentExamTimeIsUp(triesLeft = EXAM_TIME_UP_TRIES) {
    const stillAtZero = () => showExamSimulationModal.value
      && !isStudentExamSubmitted.value
      && studentExamRemainingSeconds.value === 0

    if (!stillAtZero()) return

    refreshStudentExamAttemptStatus(false).finally(() => {
      if (!stillAtZero() || triesLeft <= 1) return

      clearTimeout(studentExamTimeUpCheck)
      studentExamTimeUpCheck = setTimeout(() => confirmStudentExamTimeIsUp(triesLeft - 1), 1000)
    })
  }

  function syncStudentAnswerDraft() {
    const current = currentStudentExamQuestion.value

    if (!current) {
      studentAnswerDraft.selected_option_id = null
      studentAnswerDraft.answer_text = ''
      return
    }

    studentAnswerDraft.selected_option_id = current.answer?.selected_option_id ?? null
    studentAnswerDraft.answer_text = current.answer?.answer_text ?? ''
  }

  function studentQuestionReviewStatus(question) {
    if (!question) return 'missed'

    if (!questionHasAnswer(question)) {
      return 'missed'
    }

    if (question.answer?.is_correct === true) {
      return 'correct'
    }

    if (question.answer?.is_correct === false) {
      return 'incorrect'
    }

    return 'answered'
  }

  function studentQuestionReviewLabel(question) {
    const status = studentQuestionReviewStatus(question)

    if (status === 'correct') return 'Correct answer'
    if (status === 'incorrect') return 'Incorrect answer'
    if (status === 'missed') return 'No answer submitted'
    return 'Answer submitted'
  }

  function studentQuestionReviewMessage(question) {
    const status = studentQuestionReviewStatus(question)

    if (status === 'correct') return 'You answered this item correctly.'
    if (status === 'incorrect') return 'Your selected answer did not match the answer key.'
    if (status === 'missed') return 'This item was left unanswered in the submitted attempt.'
    return 'This item was submitted and is ready for review.'
  }

  function studentQuestionCorrectAnswerText(question) {
    const label = String(question?.correct_answer?.label ?? '').trim()
    const text = String(question?.correct_answer?.text ?? '').trim()

    if (label && text) return `${label}. ${text}`
    if (text) return text
    if (label) return label
    return ''
  }

  function examOptionCardClass(option) {
    const currentQuestion = currentStudentExamQuestion.value
    const optionId = Number(option?.id ?? 0)
    const selectedOptionId = Number(studentAnswerDraft.selected_option_id ?? 0)
    const isSelected = optionId > 0 && selectedOptionId > 0 && optionId === selectedOptionId
    const reviewStatus = studentQuestionReviewStatus(currentQuestion)
    const isCorrectOption = isStudentExamSubmitted.value && option?.is_correct === true
    const isIncorrectSelection = isStudentExamSubmitted.value && isSelected && reviewStatus === 'incorrect'
    const isNeutralSubmittedOption = isStudentExamSubmitted.value && !isSelected && !isCorrectOption

    return {
      selected: isSelected,
      'submitted-correct': isCorrectOption,
      'submitted-incorrect': isIncorrectSelection,
      'submitted-neutral': isNeutralSubmittedOption,
    }
  }

  function questionHasAnswer(question) {
    if (!question) return false

    const hasSelectedOption = question.answer?.selected_option_id !== null && question.answer?.selected_option_id !== undefined
    const hasTextAnswer = String(question.answer?.answer_text ?? '').trim().length > 0

    return hasSelectedOption || hasTextAnswer
  }

  function isQuestionVisited(questionId) {
    return studentExamVisitedQuestionIds.value.includes(Number(questionId))
  }

  function markQuestionVisited(questionId) {
    const normalizedId = Number(questionId)
    if (!normalizedId) return
    if (isQuestionVisited(normalizedId)) return

    studentExamVisitedQuestionIds.value = [
      ...studentExamVisitedQuestionIds.value,
      normalizedId,
    ]
  }

  function markCurrentQuestionVisited() {
    const currentQuestionId = currentStudentExamQuestion.value?.question_id
    if (!currentQuestionId) return

    markQuestionVisited(currentQuestionId)
  }

  function questionPaletteStatus(question, index) {
    if (!question) return 'pre-pending'

    const hasAnswer = questionHasAnswer(question)

    if (isStudentExamSubmitted.value) {
      if (!hasAnswer) return 'post-missed'

      if (question.answer?.is_correct === true) return 'post-correct'
      if (question.answer?.is_correct === false) return 'post-incorrect'

      return 'post-answered'
    }

    if (studentExamCurrentIndex.value === index) return 'pre-current'
    if (hasAnswer) return 'pre-answered'
    if (isQuestionVisited(question.question_id)) return 'pre-blank'
    return 'pre-pending'
  }

  function questionPaletteClass(question, index) {
    const status = questionPaletteStatus(question, index)

    return {
      'is-current': studentExamCurrentIndex.value === index,
      'has-bookmark': Boolean(question?.is_bookmarked),
      'pre-current': status === 'pre-current',
      'pre-answered': status === 'pre-answered',
      'pre-blank': status === 'pre-blank',
      'pre-pending': status === 'pre-pending',
      'post-correct': status === 'post-correct',
      'post-incorrect': status === 'post-incorrect',
      'post-missed': status === 'post-missed',
      'post-answered': status === 'post-answered',
    }
  }

  function applyStudentAttemptPayload(payload, preferredQuestionId = null, options = {}) {
    const { preserveReviewVisibility = false, syncRoomExam = true } = options
    const nextAttemptStatus = String(payload?.attempt?.status ?? '').toLowerCase()
    const previousVisitedQuestionIds = [...studentExamVisitedQuestionIds.value]
    const examPayload = payload?.exam
      ? {
          ...payload.exam,
          delivery_mode: normalizeExamDeliveryMode(payload.exam.delivery_mode),
        }
      : null

    studentExamAttempt.value = payload?.attempt ?? null
    studentExamFeedback.value = payload?.feedback ?? null
    selectedStudentExam.value = examPayload ?? selectedStudentExam.value
    studentExamQuestions.value = payload?.questions ?? []
    // Defaults to jumping straight to the result page on submission (see the
    // isStudentExamResultSummaryVisible watcher) -- preserveReviewVisibility
    // lets a caller that already wants review mode (e.g. the "review a past
    // attempt" deep link) keep it true without a transient false in between.
    if (!preserveReviewVisibility) {
      studentExamReviewVisible.value = nextAttemptStatus === 'submitted' ? false : studentExamReviewVisible.value
    }
    if (syncRoomExam) {
      syncSelectedRoomAssignedExam(examPayload, payload?.attempt ?? null)
    }

    const answeredQuestionIds = studentExamQuestions.value
      .filter((question) => questionHasAnswer(question))
      .map((question) => Number(question.question_id))

    studentExamVisitedQuestionIds.value = Array.from(new Set([
      ...previousVisitedQuestionIds,
      ...answeredQuestionIds,
    ]))

    const defaultIndex = studentExamQuestions.value.findIndex((question) => !question.answer?.selected_option_id && !question.answer?.answer_text)
    const preferredIndex = preferredQuestionId
      ? studentExamQuestions.value.findIndex((question) => question.question_id === preferredQuestionId)
      : -1

    if (preferredIndex >= 0) {
      studentExamCurrentIndex.value = preferredIndex
    } else if (defaultIndex >= 0) {
      studentExamCurrentIndex.value = defaultIndex
    } else {
      studentExamCurrentIndex.value = 0
    }

    startStudentExamTimer(payload?.attempt?.remaining_seconds)
    syncStudentAnswerDraft()
  }

  function syncSelectedRoomAssignedExam(examPayload, attemptPayload) {
    if (!selectedRoom.value || !Array.isArray(selectedRoom.value.assigned_exams)) return

    const examId = Number(examPayload?.id ?? 0)
    if (!Number.isFinite(examId) || examId < 1) return

    selectedRoom.value = {
      ...selectedRoom.value,
      assigned_exams: selectedRoom.value.assigned_exams.map((exam) => {
        if (Number(exam.id) !== examId) {
          return exam
        }

        const maxAttempts = Number(exam.student_max_attempts ?? studentMaxAttempts(exam))
        const currentSubmittedAttempts = Number(exam.student_submitted_attempts ?? 0)
        const wasAlreadySubmitted = String(exam.student_attempt_state ?? '').toLowerCase() === 'submitted'
        const nextSubmittedAttempts = attemptPayload?.status === 'submitted'
          ? Math.max(currentSubmittedAttempts + (wasAlreadySubmitted ? 0 : 1), currentSubmittedAttempts || 1)
          : currentSubmittedAttempts
        const attemptsRemaining = Math.max(0, maxAttempts - nextSubmittedAttempts)

        return {
          ...exam,
          ...examPayload,
          delivery_mode: normalizeExamDeliveryMode(examPayload?.delivery_mode ?? exam.delivery_mode),
          student_attempt_state: attemptPayload?.status ?? exam.student_attempt_state ?? 'not_started',
          student_attempt_id: attemptPayload?.id ?? exam.student_attempt_id ?? null,
          student_submitted_at: attemptPayload?.submitted_at ?? exam.student_submitted_at ?? null,
          student_submitted_attempts: nextSubmittedAttempts,
          student_max_attempts: maxAttempts,
          student_attempts_remaining: attemptsRemaining,
          student_can_start_attempt: attemptsRemaining > 0,
        }
      }),
    }
  }

  // Opens the overlay on one finished attempt, for reading only. Used by the
  // deep link back from the result page's "Review Answers" button. It loads
  // that attempt and nothing else: it used to go through openExamSimulation,
  // which starts an attempt whenever one can be started, so a student who
  // still had a retake left and pressed "Review Answers" had the retake
  // started on them, timer running.
  async function openSubmittedAttemptReview(exam, attemptId) {
    if (!attemptId || !selectedRoomId.value) return

    selectedStudentExam.value = {
      ...exam,
      delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
    }
    studentExamError.value = ''
    showExamSimulationModal.value = true
    studentExamLoading.value = true

    try {
      // Set before the payload lands so the result-redirect watcher never
      // observes a (momentary) result-summary state for this attempt.
      studentExamReviewVisible.value = true
      const { data } = await services.getAttempt(attemptId)

      if (String(data?.attempt?.status ?? '').toLowerCase() !== 'submitted') {
        closeExamSimulationModal()
        return
      }

      // The room's list already says where this exam stands (a retake may be
      // waiting, or under way); looking at an older attempt must not rewrite it.
      applyStudentAttemptPayload(data, null, { preserveReviewVisibility: true, syncRoomExam: false })
      roomMessage.value = 'Reviewing your submitted attempt.'
    } catch (error) {
      studentExamReviewVisible.value = false
      studentExamError.value = firstApiError(error, 'Unable to open this attempt for review.')
    } finally {
      studentExamLoading.value = false
    }
  }

  // The one button an archived exam can still have: see archivedExamAction.
  function openArchivedExam(exam) {
    const action = archivedExamAction(exam)

    if (action === 'resume') {
      openExamSimulation(exam)
      return
    }

    if (action === 'result') {
      router.push({ name: 'dashboard-exam-result', params: { attemptId: studentSubmittedAttemptId(exam) } })
    }
  }

  async function openExamSimulation(exam) {
    if (!canStudentOpenExam(exam) || !selectedRoomId.value) return

    // No retakes left, nothing to answer -- the only thing this exam entry
    // can mean is "show me my result", so skip the overlay and go straight
    // to its own page instead of opening/closing it just to redirect.
    if (isStudentExamRetakeLimitReached(exam)) {
      const submittedAttemptId = studentSubmittedAttemptId(exam)

      if (submittedAttemptId) {
        router.push({ name: 'dashboard-exam-result', params: { attemptId: submittedAttemptId } })
      }

      return
    }

    selectedStudentExam.value = {
      ...exam,
      delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
    }
    studentExamError.value = ''
    showExamSimulationModal.value = true
    studentExamLoading.value = true

    try {
      const { data } = await services.startExam(exam.id, {
        room_id: selectedRoomId.value,
      })

      const isFreshTake = data?.resumed === false
      const isShuffleEnabled = Boolean(data?.exam?.shuffle_questions)

      if (isFreshTake) {
        studentExamVisitedQuestionIds.value = []
      }

      if (isFreshTake && isShuffleEnabled && Array.isArray(data?.questions)) {
        data.questions = data.questions.map((question) => ({
          ...question,
          is_bookmarked: false,
        }))
      }

      applyStudentAttemptPayload(data)
      startStudentExamAutoSync()
      roomMessage.value = data?.message ?? 'Exam attempt is ready.'
    } catch (error) {
      stopStudentExamAutoSync()
      studentExamError.value = firstApiError(error, 'Unable to start exam attempt.')
    } finally {
      studentExamLoading.value = false
    }
  }

  function closeExamSimulationModal() {
    clearStudentExamTimer()
    stopStudentExamAutoSync()

    showExamSimulationModal.value = false
    showStudentSubmitConfirmModal.value = false
    showStudentExitConfirmModal.value = false
    selectedStudentExam.value = null
    studentExamAttempt.value = null
    studentExamFeedback.value = null
    studentExamReviewVisible.value = false
    studentExamQuestions.value = []
    studentExamCurrentIndex.value = 0
    studentExamLoading.value = false
    studentExamSaving.value = false
    studentExamBookmarking.value = false
    studentExamSubmitting.value = false
    studentExamError.value = ''
    studentExamRemainingSeconds.value = null
    studentExamVisitedQuestionIds.value = []
    studentAnswerDraft.selected_option_id = null
    studentAnswerDraft.answer_text = ''
  }

  function goToStudentExamQuestionIndex(targetIndex) {
    const maxIndex = studentExamQuestions.value.length - 1
    if (maxIndex < 0) return

    const resolvedTargetIndex = Math.min(Math.max(targetIndex, 0), maxIndex)

    markCurrentQuestionVisited()
    studentExamCurrentIndex.value = resolvedTargetIndex
    syncStudentAnswerDraft()
  }

  function goToStudentExamQuestion(step) {
    goToStudentExamQuestionIndex(studentExamCurrentIndex.value + step)
  }

  function handleExamAttemptCloseClick() {
    if (studentExamSubmitting.value) return

    if (isStudentExamSubmitted.value) {
      closeExamSimulationModal()
      return
    }

    showStudentSubmitConfirmModal.value = false
    showStudentExitConfirmModal.value = true
    studentExamError.value = ''
  }

  function openStudentExamReview() {
    if (!isStudentExamSubmitted.value || studentExamQuestions.value.length === 0) return

    const preferredIndex = studentExamQuestions.value.findIndex((question) => {
      const status = studentQuestionReviewStatus(question)
      return status === 'incorrect' || status === 'missed'
    })

    studentExamReviewVisible.value = true
    studentExamCurrentIndex.value = preferredIndex >= 0 ? preferredIndex : 0

    if (!isExamMobileViewport.value) {
      examAttemptSidebarCollapsed.value = false
    }

    syncStudentAnswerDraft()
  }

  function showStudentExamResultSummary() {
    if (!isStudentExamSubmitted.value) return

    studentExamReviewVisible.value = false

    if (isExamMobileViewport.value) {
      examAttemptSidebarCollapsed.value = true
    }
  }

  function closeStudentExamExitConfirm() {
    if (studentExamSubmitting.value) return
    showStudentExitConfirmModal.value = false
  }

  function confirmStudentExamExit() {
    if (studentExamSubmitting.value) return

    if (studentExamSaving.value || studentExamBookmarking.value) {
      studentExamError.value = 'Please wait for the latest answer changes to finish syncing before exiting.'
      return
    }

    showStudentExitConfirmModal.value = false
    closeExamSimulationModal()
  }

  function openStudentExamSubmitConfirm() {
    if (isStudentExamSubmitted.value) return
    if (!studentExamAttempt.value?.id) return
    if (studentExamLoading.value || studentExamSubmitting.value) return

    showStudentExitConfirmModal.value = false
    showStudentSubmitConfirmModal.value = true
    studentExamError.value = ''
  }

  function closeStudentExamSubmitConfirm() {
    if (studentExamSubmitting.value) return
    showStudentSubmitConfirmModal.value = false
  }

  async function confirmStudentExamSubmit() {
    if (studentExamSubmitting.value) return

    if (studentExamSaving.value || studentExamBookmarking.value) {
      studentExamError.value = 'Please wait for the latest answer changes to finish syncing before submitting.'
      return
    }

    showStudentSubmitConfirmModal.value = false
    await submitStudentExam()
  }

  async function saveStudentExamAnswer() {
    const attemptId = studentExamAttempt.value?.id
    const currentQuestion = currentStudentExamQuestion.value
    if (!attemptId || !currentQuestion || isStudentExamSubmitted.value) return

    const normalizedDraftText = studentAnswerDraft.answer_text?.trim() || null
    const normalizedSavedText = currentQuestion.answer?.answer_text?.trim() || null
    const selectedOptionId = studentAnswerDraft.selected_option_id ?? null
    const savedOptionId = currentQuestion.answer?.selected_option_id ?? null

    if (currentQuestion.question_type === 'open_ended' && normalizedDraftText === normalizedSavedText) return
    if (currentQuestion.question_type !== 'open_ended' && selectedOptionId === savedOptionId) return

    studentExamSaving.value = true
    studentExamError.value = ''
    markQuestionVisited(currentQuestion.question_id)

    const payload = {
      question_id: currentQuestion.question_id,
    }

    if (currentQuestion.question_type === 'open_ended') {
      payload.answer_text = normalizedDraftText
    } else {
      payload.selected_option_id = selectedOptionId
    }

    try {
      const { data } = await services.saveAnswer(attemptId, payload)
      applyStudentAttemptPayload(data, currentQuestion.question_id)
    } catch (error) {
      studentExamError.value = firstApiError(error, 'Unable to save answer.')
    } finally {
      studentExamSaving.value = false
    }
  }

  async function handleStudentOptionSelect(optionId) {
    if (isCurrentQuestionInputLocked.value) return

    studentAnswerDraft.selected_option_id = Number(optionId)
    await saveStudentExamAnswer()
  }

  async function handleStudentOpenEndedBlur() {
    if (isCurrentQuestionInputLocked.value) return
    await saveStudentExamAnswer()
  }

  async function toggleCurrentQuestionBookmark() {
    const attemptId = studentExamAttempt.value?.id
    const currentQuestion = currentStudentExamQuestion.value
    if (!attemptId || !currentQuestion || isStudentExamSubmitted.value) return
    if (!isStudentOpenNavigationMode.value) return
    if (studentExamBookmarking.value) return

    studentExamBookmarking.value = true
    studentExamError.value = ''

    try {
      const { data } = await services.bookmarkQuestion(
        attemptId,
        currentQuestion.question_id,
        { is_bookmarked: !Boolean(currentQuestion.is_bookmarked) },
      )
      applyStudentAttemptPayload(data, currentQuestion.question_id)
    } catch (error) {
      studentExamError.value = firstApiError(error, 'Unable to update bookmark.')
    } finally {
      studentExamBookmarking.value = false
    }
  }

  async function submitStudentExam() {
    const attemptId = studentExamAttempt.value?.id
    if (!attemptId || isStudentExamSubmitted.value) return

    markCurrentQuestionVisited()
    studentExamSubmitting.value = true
    studentExamError.value = ''

    try {
      const { data } = await services.submitAttempt(attemptId)
      applyStudentAttemptPayload(data)
      roomMessage.value = 'Exam submitted. Result is now available.'
    } catch (error) {
      studentExamError.value = firstApiError(error, 'Unable to submit exam attempt.')
    } finally {
      studentExamSubmitting.value = false
    }
  }

  function openCreateRoomModal() {
    if (!canCreateRooms.value) return
    roomName.value = ''
    showCreateRoomModal.value = true
  }

  function closeCreateRoomModal() {
    showCreateRoomModal.value = false
    roomName.value = ''
  }

  function openJoinRoomModal() {
    joinCode.value = ''
    showJoinRoomModal.value = true
  }

  function closeJoinRoomModal() {
    showJoinRoomModal.value = false
    joinCode.value = ''
  }

  function openEditRoomModal() {
    if (!selectedRoom.value) return
    editRoomName.value = selectedRoom.value.name ?? ''
    showEditRoomModal.value = true
  }

  function closeEditRoomModal() {
    showEditRoomModal.value = false
    editRoomName.value = ''
  }

  function openDeleteRoomModal() {
    if (!selectedRoom.value) return
    // The dialog shows roomError, so an error left over from something else
    // must not be waiting in it when it opens.
    roomError.value = ''
    showDeleteRoomModal.value = true
  }

  function closeDeleteRoomModal() {
    showDeleteRoomModal.value = false
  }

  /*
   * What stands in the way of removing the open room, from the server
   * (room.removal). Delete is for a room that was never used: once it holds
   * results, or an exam is still open or scheduled in it, the dialog says so
   * and offers Archive, which keeps everything.
   */
  const roomRemoval = computed(() => {
    const removal = selectedRoom.value?.removal ?? {}
    const pendingExams = Array.isArray(removal.pending_exams) ? removal.pending_exams : []

    return {
      hasResults: Boolean(removal.has_results),
      pendingExams,
      attemptsInProgress: Number(removal.attempts_in_progress ?? 0),
      canDelete: !removal.has_results && pendingExams.length === 0,
      canArchive: Number(removal.attempts_in_progress ?? 0) === 0,
    }
  })

  function openArchiveRoomModal() {
    if (!selectedRoom.value) return
    roomError.value = ''
    showDeleteRoomModal.value = false
    showArchiveRoomModal.value = true
  }

  function closeArchiveRoomModal() {
    showArchiveRoomModal.value = false
  }

  // Quiet: the archived list is a side panel, and failing to count it is not
  // worth a banner over the rooms themselves.
  async function loadArchivedRooms() {
    if (modeKey !== 'management') return

    try {
      const { data } = await services.getArchivedRooms()
      archivedRooms.value = data.rooms ?? []
    } catch {
      // Keeps what it had.
    }
  }

  async function handleArchiveRoom() {
    if (!selectedRoomId.value) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const name = selectedRoom.value?.name ?? 'The room'

      await services.archiveRoom(selectedRoomId.value)
      closeArchiveRoomModal()
      roomMessage.value = `${name} was archived. Its students, exams and results are kept, and you can restore it from Archived Rooms.`
      selectedRoomId.value = null
      selectedRoom.value = null
      await Promise.all([fetchRooms(), loadArchivedRooms()])
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to archive this room.')
    } finally {
      roomLoading.value = false
    }
  }

  function openArchivedRoomsModal() {
    roomError.value = ''
    showArchivedRoomsModal.value = true
    loadArchivedRooms()
  }

  function closeArchivedRoomsModal() {
    showArchivedRoomsModal.value = false
  }

  async function handleRestoreRoom(room) {
    if (!room?.id) return

    restoringRoomId.value = room.id
    roomError.value = ''
    roomMessage.value = ''

    try {
      await services.restoreRoom(room.id)
      await loadArchivedRooms()
      if (archivedRooms.value.length === 0) closeArchivedRoomsModal()
      roomMessage.value = `${room.name} was restored.`
      await fetchRooms(room.id)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to restore this room.')
    } finally {
      restoringRoomId.value = null
    }
  }

  function openRoomExportModal() {
    if (!selectedRoom.value) return
    roomExportForm.format = 'xlsx'
    showRoomExportModal.value = true
  }

  function closeRoomExportModal() {
    if (roomExporting.value) return
    showRoomExportModal.value = false
    roomExportForm.format = 'xlsx'
  }

  function openLeaveRoomModal() {
    if (!selectedRoom.value) return
    // The dialog shows roomError (leaving is refused mid-exam).
    roomError.value = ''
    showLeaveRoomModal.value = true
  }

  function closeLeaveRoomModal() {
    showLeaveRoomModal.value = false
  }

  function normalizeRoomDetails(room) {
    if (!room) return null

    const normalizeRoomExam = (exam) => ({
      ...exam,
      schedule_start_at: exam.schedule_start_at ?? exam.scheduled_at ?? null,
      schedule_end_at: exam.schedule_end_at ?? null,
      delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
    })

    return {
      ...room,
      members: room.members ?? [],
      assigned_exams: (room.assigned_exams ?? []).map(normalizeRoomExam),
      archived_exams: (room.archived_exams ?? []).map(normalizeRoomExam),
    }
  }

  async function fetchRoomDetails(roomId) {
    if (!roomId) return

    roomDetailsLoading.value = true
    roomError.value = ''

    try {
      const { data } = await services.getRoom(roomId)
      const room = data.room ?? null

      selectedRoom.value = normalizeRoomDetails(room)
      selectedRoomId.value = room?.id ?? null
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to load room details right now.')
      selectedRoom.value = null
      selectedRoomId.value = null
      closeExamSimulationModal()
    } finally {
      roomDetailsLoading.value = false
    }
  }

  async function selectRoom(roomId) {
    closeExamSimulationModal()
    closeRoomLiveBoard()
    await fetchRoomDetails(roomId)
  }

  async function fetchRooms(preferredRoomId = null) {
    roomLoading.value = true
    roomError.value = ''

    try {
      const { data } = await services.getRooms()
      rooms.value = data.rooms ?? []

      if (rooms.value.length === 0) {
        showJoinRoomModal.value = false
        showEditRoomModal.value = false
        showDeleteRoomModal.value = false
        showLeaveRoomModal.value = false
        closeExamSimulationModal()
        selectedRoomId.value = null
        selectedRoom.value = null
        return
      }

      const hasPreferredRoom = preferredRoomId !== null && rooms.value.some((room) => room.id === preferredRoomId)
      const hasCurrentRoom = selectedRoomId.value !== null && rooms.value.some((room) => room.id === selectedRoomId.value)
      const roomIdToLoad = hasPreferredRoom
        ? preferredRoomId
        : (hasCurrentRoom ? selectedRoomId.value : rooms.value[0].id)

      await fetchRoomDetails(roomIdToLoad)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to load rooms right now.')
    } finally {
      roomLoading.value = false
    }
  }

  /*
   * The same two requests as fetchRooms(), made quietly: no loading state,
   * no message cleared, the room on screen kept on screen, and a failure
   * kept to itself. What changes is only what the server says has changed.
   *
   * A student's own list of rooms changes when they join or leave one, which
   * this page does itself, so on the minute it asks only about the room that
   * is open and leaves the list for when they come back to the page. Staff
   * are few and their list carries each room's member count, so theirs is
   * read every time.
   */
  async function refreshRoomsQuietly({ returning = false } = {}) {
    // No room on screen to keep: the first load failed, or there were no
    // rooms yet. The list is asked for quietly, and only if it has something
    // now does the ordinary load take over (it shows a loading state, which
    // would otherwise blink once a minute over "Join a Room").
    if (selectedRoomId.value === null) {
      const { data } = await services.getRooms()
      if ((data.rooms ?? []).length > 0) await fetchRooms()
      return
    }

    if (mode !== 'student' || returning) {
      const { data } = await services.getRooms()
      rooms.value = data.rooms ?? []
    }

    const roomId = selectedRoomId.value

    // The open room is no longer theirs (removed from it, or it was deleted):
    // that is a change of page, so it goes through the ordinary load.
    if (!rooms.value.some((room) => room.id === roomId)) {
      await fetchRooms()
      return
    }

    const { data } = await services.getRoom(roomId)

    // They moved to another room, or into an exam, while this was on its way.
    if (selectedRoomId.value !== roomId || !isSafeToRefreshRooms()) return

    selectedRoom.value = normalizeRoomDetails(data.room ?? null) ?? selectedRoom.value
  }

  // Not under a dialog (it would redraw what the dialog is about), not during
  // an exam (the exam keeps itself current), not while a load is already out.
  function isSafeToRefreshRooms() {
    return !showExamSimulationModal.value
      && !roomLiveBoardActive.value
      && !roomLoading.value
      && !roomDetailsLoading.value
      && !showCreateRoomModal.value
      && !showJoinRoomModal.value
      && !showEditRoomModal.value
      && !showDeleteRoomModal.value
      && !showArchiveRoomModal.value
      && !showArchivedRoomsModal.value
      && !showRoomExportModal.value
      && !showLeaveRoomModal.value
  }

  useLiveRefresh(refreshRoomsQuietly, { every: ROOM_LIVE_REFRESH_MS, when: isSafeToRefreshRooms })

  async function handleCreateRoom() {
    if (!canCreateRooms.value) return
    if (!roomName.value.trim()) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const { data } = await services.createRoom({ name: roomName.value.trim() })
      roomName.value = ''
      showCreateRoomModal.value = false
      roomMessage.value = 'Room created. Share the room code with students.'
      await fetchRooms(data?.room?.id ?? null)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to create room.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleUpdateRoom() {
    if (!selectedRoomId.value || !editRoomName.value.trim()) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      await services.updateRoom(selectedRoomId.value, { name: editRoomName.value.trim() })
      closeEditRoomModal()
      roomMessage.value = 'Room updated successfully.'
      await fetchRooms(selectedRoomId.value)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to update room.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleDeleteRoom() {
    if (!selectedRoomId.value) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      await services.deleteRoom(selectedRoomId.value)
      closeDeleteRoomModal()
      roomMessage.value = 'Room deleted successfully.'
      selectedRoomId.value = null
      selectedRoom.value = null
      await fetchRooms()
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to delete room.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleJoinRoom() {
    if (!joinCode.value.trim()) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const { data } = await services.joinRoom({ code: joinCode.value.trim().toUpperCase() })
      showJoinRoomModal.value = false
      joinCode.value = ''
      roomMessage.value = 'Joined room successfully.'
      await fetchRooms(data?.room?.id ?? null)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to join room with that code.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleLeaveRoom() {
    if (!selectedRoomId.value) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      await services.leaveRoom(selectedRoomId.value)
      showLeaveRoomModal.value = false
      closeExamSimulationModal()
      roomMessage.value = 'You have left the room.'
      selectedRoomId.value = null
      selectedRoom.value = null
      await fetchRooms()
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to leave room right now.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleKickRoomMember(member) {
    if (!selectedRoomId.value) return
    if (!canRemoveRoomMember(member)) return

    const memberId = Number(member?.id ?? 0)
    if (!Number.isFinite(memberId) || memberId < 1) return

    const memberName = String(member?.name ?? 'this student').trim() || 'this student'
    const roomNameLabel = String(selectedRoom.value?.name ?? 'this room').trim() || 'this room'

    if (typeof window !== 'undefined') {
      const confirmed = window.confirm(`Remove ${memberName} from ${roomNameLabel}?`)
      if (!confirmed) return
    }

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const { data } = await services.removeRoomMember(selectedRoomId.value, memberId)
      roomMessage.value = data?.message ?? `${memberName} has been removed from the room.`
      await fetchRooms(selectedRoomId.value)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to remove student from this room right now.')
    } finally {
      roomLoading.value = false
    }
  }

  async function exportRoomGrades() {
    if (!selectedRoom.value) return
    roomExporting.value = true
    roomError.value = ''
    roomMessage.value = ''

    const format = ['xlsx', 'pdf', 'csv'].includes(String(roomExportForm.format))
      ? String(roomExportForm.format)
      : 'xlsx'
    const code = String(selectedRoom.value.code || '').trim()
    const fallbackName = `room-${code || selectedRoom.value.id}-grades.${format}`

    try {
      const response = await services.exportRoomGrades(selectedRoom.value.id, { format })
      saveDownloadedBlob(response, fallbackName)
      roomMessage.value = 'Room grade export generated successfully.'
      showRoomExportModal.value = false
    } catch (e) {
      roomError.value = await resolveBlobApiError(e, 'Failed to export room grades.')
    } finally {
      roomExporting.value = false
    }
  }

  async function handleArchiveRoomExam(exam) {
    if (!selectedRoomId.value || !isManagementRole.value) return

    const examId = Number(exam?.id ?? 0)
    if (!Number.isFinite(examId) || examId < 1) return

    const examTitle = String(exam?.title ?? 'this exam').trim() || 'this exam'
    const roomNameLabel = String(selectedRoom.value?.name ?? 'this room').trim() || 'this room'

    if (typeof window !== 'undefined') {
      const confirmed = window.confirm(`Archive ${examTitle} from ${roomNameLabel}?`)
      if (!confirmed) return
    }

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const { data } = await services.archiveRoomExam(selectedRoomId.value, examId)
      roomMessage.value = data?.message ?? `${examTitle} archived from the room.`
      await fetchRooms(selectedRoomId.value)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to archive this room exam right now.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleRestoreRoomExam(exam) {
    if (!selectedRoomId.value || !isManagementRole.value) return

    const examId = Number(exam?.id ?? 0)
    if (!Number.isFinite(examId) || examId < 1) return

    const examTitle = String(exam?.title ?? 'this exam').trim() || 'this exam'

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const { data } = await services.restoreRoomExam(selectedRoomId.value, examId)
      roomMessage.value = data?.message ?? `${examTitle} restored to the current room view.`
      await fetchRooms(selectedRoomId.value)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to restore this room exam right now.')
    } finally {
      roomLoading.value = false
    }
  }

  function resetLiveBoardState() {
    liveBoardRows.value = []
    liveBoardItemSummary.value = []
    liveBoardSummary.value = {
      students_total: 0,
      attempts_started: 0,
      attempts_submitted: 0,
    }
    liveBoardError.value = ''
    liveBoardUpdatedAt.value = null
  }

  function stopLiveBoardAutoRefresh() {
    if (!liveBoardRefreshInterval) return
    clearInterval(liveBoardRefreshInterval)
    liveBoardRefreshInterval = null
  }

  function startLiveBoardAutoRefresh() {
    stopLiveBoardAutoRefresh()
    liveBoardRefreshInterval = setInterval(() => {
      if (!roomLiveBoardActive.value || !liveBoardExam.value?.id || !liveBoardRoomId.value) return
      loadLiveBoard(true)
    }, 5000)
  }

  async function openRoomLiveBoard(exam) {
    if (!canViewExamResults.value) return

    const roomId = Number(selectedRoom.value?.id ?? selectedRoomId.value)
    if (!Number.isFinite(roomId) || roomId < 1) {
      roomError.value = 'Select a room first before opening the live board.'
      return
    }

    liveBoardExam.value = {
      ...exam,
      delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
    }
    liveBoardRoomId.value = roomId
    liveBoardOptions.show_names = true
    liveBoardOptions.show_responses = true
    liveBoardOptions.show_results = true
    resetLiveBoardState()
    roomLiveBoardActive.value = true

    if (typeof window !== 'undefined') {
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }

    await loadLiveBoard(false)
    startLiveBoardAutoRefresh()
  }

  function closeRoomLiveBoard() {
    roomLiveBoardActive.value = false
    stopLiveBoardAutoRefresh()
    liveBoardExam.value = null
    liveBoardRoomId.value = null
    resetLiveBoardState()
  }

  async function loadLiveBoard(silent = false) {
    if (!canViewExamResults.value) return

    const examId = liveBoardExam.value?.id
    const roomId = Number(liveBoardRoomId.value)
    if (!examId || !Number.isFinite(roomId) || roomId < 1) return

    if (silent) {
      liveBoardRefreshing.value = true
    } else {
      liveBoardLoading.value = true
    }

    if (!silent) {
      liveBoardError.value = ''
    }

    try {
      const { data } = await services.getLiveBoard(examId, roomId)

      if (data?.exam) {
        liveBoardExam.value = {
          ...data.exam,
          delivery_mode: normalizeExamDeliveryMode(data.exam.delivery_mode),
        }
      }

      liveBoardRows.value = data.rows ?? []
      liveBoardItemSummary.value = data.item_summary ?? []
      liveBoardSummary.value = data.summary ?? {
        students_total: 0,
        attempts_started: 0,
        attempts_submitted: 0,
      }
      liveBoardUpdatedAt.value = data.generated_at ?? new Date().toISOString()
    } catch (error) {
      liveBoardError.value = firstApiError(error, 'Unable to load live dashboard.')
    } finally {
      if (silent) {
        liveBoardRefreshing.value = false
      } else {
        liveBoardLoading.value = false
      }
    }
  }

  function liveBoardDisplayName(row, index) {
    if (liveBoardOptions.show_names) return row?.user?.name || 'Unknown Student'
    return `Student ${index + 1}`
  }

  function liveBoardProgressLabel(row) {
    if (!row?.attempt) return 'Not started'

    const answered = Number(row.attempt.answered_count ?? 0)
    const total = Number(row.attempt.total_items ?? 0)
    const status = row.attempt.status === 'submitted' ? 'Submitted' : 'In progress'
    return `${answered}/${total} • ${status}`
  }

  function liveBoardResponseText(item) {
    const raw = String(item?.response ?? '').trim()
    if (!raw) return 'Answered'
    return raw.length > 30 ? `${raw.slice(0, 30)}...` : raw
  }

  function liveBoardCellText(item) {
    if (!item?.answered) return '--'

    if (liveBoardOptions.show_results) {
      if (item.is_correct === true) {
        return liveBoardOptions.show_responses ? `Correct: ${liveBoardResponseText(item)}` : 'Correct'
      }
      if (item.is_correct === false) {
        return liveBoardOptions.show_responses ? `Wrong: ${liveBoardResponseText(item)}` : 'Wrong'
      }
    }

    if (liveBoardOptions.show_responses) return liveBoardResponseText(item)
    return 'Answered'
  }

  function liveBoardCellClass(item) {
    if (!item?.answered) return 'pending'
    if (!liveBoardOptions.show_results) return 'answered'
    if (item.is_correct === true) return 'correct'
    if (item.is_correct === false) return 'incorrect'
    return 'answered'
  }

  function liveBoardItemSummaryText(item) {
    if (liveBoardOptions.show_results) {
      if (!Number.isFinite(Number(item?.correct_percent))) return '--'
      return `${Number(item.correct_percent)}%`
    }

    if (!Number.isFinite(Number(item?.answered_percent))) return '--'
    return `${Number(item.answered_percent)}%`
  }

  watch(
    () => currentStudentExamQuestion.value?.question_id,
    () => {
      syncStudentAnswerDraft()
    },
  )

  watch(
    () => studentExamRemainingSeconds.value,
    (remainingSeconds) => {
      if (remainingSeconds !== 0) return

      clearStudentExamTimer()
      confirmStudentExamTimeIsUp()
    },
  )

  return {
    isManagementView,
    isAdminRole,
    isStaffRole,
    isManagementRole,
    canCreateRooms,
    canViewExamResults,
    roomName,
    joinCode,
    roomSearchQuery,
    filteredRooms,
    rooms,
    selectedRoomId,
    selectedRoom,
    showCreateRoomModal,
    showJoinRoomModal,
    showEditRoomModal,
    showDeleteRoomModal,
    showRoomExportModal,
    showLeaveRoomModal,
    editRoomName,
    roomLoading,
    roomDetailsLoading,
    roomExporting,
    roomError,
    roomMessage,
    roomExportFormats,
    roomExportForm,
    roomLiveBoardActive,
    liveBoardExam,
    liveBoardRoom,
    liveBoardRows,
    liveBoardItemSummary,
    liveBoardSummary,
    liveBoardLoading,
    liveBoardRefreshing,
    liveBoardError,
    liveBoardOptions,
    liveBoardLastUpdatedText,
    showExamSimulationModal,
    showStudentSubmitConfirmModal,
    showStudentExitConfirmModal,
    selectedStudentExam,
    studentExamAttempt,
    studentExamReviewVisible,
    studentExamQuestions,
    studentExamCurrentIndex,
    isExamMobileViewport,
    examAttemptSidebarCollapsed,
    studentExamLoading,
    studentExamSaving,
    studentExamBookmarking,
    studentExamSubmitting,
    studentExamError,
    studentExamRemainingSeconds,
    studentAnswerDraft,
    roomCollectionLabel,
    selectedRoomActiveExams,
    selectedRoomArchivedExams,
    selectedRoomCurrentMembers,
    selectedRoomYearSummary,
    selectedRoomRosterCopy,
    selectedRoomExamSummary,
    currentStudentExamQuestion,
    currentQuestionStem,
    isStudentExamSubmitted,
    isStudentExamResultSummaryVisible,
    isStudentOpenNavigationMode,
    isCurrentQuestionInputLocked,
    studentExamUnansweredCount,
    displayMemberRole,
    yearLevelLabel,
    canRemoveRoomMember,
    canStudentOpenExam,
    isStudentExamInProgress,
    isStudentExamCompleted,
    isStudentExamRetakeLimitReached,
    studentExamActionLabel,
    studentExamAvailabilityText,
    archivedExamAction,
    archivedExamActionLabel,
    archivedExamStatusText,
    openArchivedExam,
    examOptionCardClass,
    questionPaletteClass,
    studentQuestionReviewStatus,
    studentQuestionReviewLabel,
    studentQuestionReviewMessage,
    studentQuestionCorrectAnswerText,
    toggleExamAttemptSidebar,
    setExamQuestionListOpen,
    pickStudentExamQuestionFromList,
    openExamSimulation,
    openStudentExamReview,
    goToStudentExamQuestionIndex,
    goToStudentExamQuestion,
    handleExamAttemptCloseClick,
    showStudentExamResultSummary,
    closeStudentExamExitConfirm,
    confirmStudentExamExit,
    openStudentExamSubmitConfirm,
    closeStudentExamSubmitConfirm,
    confirmStudentExamSubmit,
    handleStudentOptionSelect,
    handleStudentOpenEndedBlur,
    toggleCurrentQuestionBookmark,
    openCreateRoomModal,
    closeCreateRoomModal,
    openJoinRoomModal,
    closeJoinRoomModal,
    openEditRoomModal,
    closeEditRoomModal,
    openDeleteRoomModal,
    closeDeleteRoomModal,
    showArchiveRoomModal,
    showArchivedRoomsModal,
    archivedRooms,
    restoringRoomId,
    roomRemoval,
    openArchiveRoomModal,
    closeArchiveRoomModal,
    handleArchiveRoom,
    openArchivedRoomsModal,
    closeArchivedRoomsModal,
    handleRestoreRoom,
    openRoomExportModal,
    closeRoomExportModal,
    openLeaveRoomModal,
    closeLeaveRoomModal,
    selectRoom,
    handleCreateRoom,
    handleUpdateRoom,
    handleDeleteRoom,
    handleJoinRoom,
    handleLeaveRoom,
    handleKickRoomMember,
    handleArchiveRoomExam,
    handleRestoreRoomExam,
    openRoomLiveBoard,
    closeRoomLiveBoard,
    loadLiveBoard,
    liveBoardDisplayName,
    liveBoardProgressLabel,
    liveBoardCellText,
    liveBoardCellClass,
    liveBoardItemSummaryText,
    exportRoomGrades,
  }
}
