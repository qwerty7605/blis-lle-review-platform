import {
  computed,
  onMounted,
  reactive,
  ref,
  watch,
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import { normalizeExamDeliveryMode } from './useDashboardFormatters'
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
} from './studentExamAvailability'
import { useDashboardDataServices } from './useDashboardDataServices'
import { useLiveRefresh } from '@/utils/liveRefresh'
import {
  ROOM_LIVE_REFRESH_MS,
  firstApiError,
  saveDownloadedBlob,
  resolveBlobApiError,
  displayMemberRole,
  yearLevelLabel,
  sortRoomMembers,
  buildRoomYearSummary,
} from './roomsHelpers'
import { useRoomLiveBoard } from './useRoomLiveBoard'
import { useStudentExamAttempt } from './useStudentExamAttempt'

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
  const roomJoiningSaving = ref(false)
  const showRoomExportModal = ref(false)
  const showLeaveRoomModal = ref(false)
  const editRoomName = ref('')
  const roomLoading = ref(false)
  const roomDetailsLoading = ref(false)
  const roomExporting = ref(false)
  const roomError = ref('')
  const roomMessage = ref('')
  // The room's Gradebook: every current student's latest score on every
  // exam the room has had. What each format is for is said under the field.
  const roomExportFormats = [
    { value: 'xlsx', label: 'Excel', hint: 'Formatted, with a sheet explaining the file. Best for your class records.' },
    { value: 'pdf', label: 'PDF', hint: 'Printable landscape pages, with exams shortened to E1, E2... and a legend.' },
    { value: 'csv', label: 'CSV', hint: 'Plain data for Google Sheets or another records system. Not formatted.' },
  ]
  const roomExportForm = reactive({
    format: 'xlsx',
  })

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

  const {
    roomLiveBoardActive,
    liveBoardExam,
    liveBoardRows,
    liveBoardItemSummary,
    liveBoardSummary,
    liveBoardLoading,
    liveBoardRefreshing,
    liveBoardError,
    liveBoardOptions,
    liveBoardRoom,
    liveBoardLastUpdatedText,
    openRoomLiveBoard,
    closeRoomLiveBoard,
    loadLiveBoard,
    liveBoardDisplayName,
    liveBoardProgressLabel,
    liveBoardCellText,
    liveBoardCellClass,
    liveBoardItemSummaryText,
  } = useRoomLiveBoard({ canViewExamResults, roomError, rooms, selectedRoom, selectedRoomId, services })

  const {
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
    currentStudentExamQuestion,
    currentQuestionStem,
    isStudentExamSubmitted,
    isStudentExamResultSummaryVisible,
    isStudentOpenNavigationMode,
    isCurrentQuestionInputLocked,
    studentExamUnansweredCount,
    toggleExamAttemptSidebar,
    setExamQuestionListOpen,
    pickStudentExamQuestionFromList,
    openReviewDeepLinkIfRequested,
    studentQuestionReviewStatus,
    studentQuestionReviewLabel,
    studentQuestionReviewMessage,
    studentQuestionCorrectAnswerText,
    examOptionCardClass,
    questionHasAnswer,
    questionPaletteClass,
    openArchivedExam,
    openExamSimulation,
    closeExamSimulationModal,
    goToStudentExamQuestionIndex,
    goToStudentExamQuestion,
    handleExamAttemptCloseClick,
    openStudentExamReview,
    showStudentExamResultSummary,
    closeStudentExamExitConfirm,
    confirmStudentExamExit,
    openStudentExamSubmitConfirm,
    closeStudentExamSubmitConfirm,
    confirmStudentExamSubmit,
    handleStudentOptionSelect,
    handleStudentOpenEndedBlur,
    toggleCurrentQuestionBookmark,
  } = useStudentExamAttempt({
    roomMessage,
    route,
    router,
    selectedRoom,
    selectedRoomActiveExams,
    selectedRoomArchivedExams,
    selectedRoomId,
    services,
  })

  onMounted(async () => {
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

  function canRemoveRoomMember(member) {
    if (!isManagementRole.value) return false

    const memberRole = String(member?.role ?? '').toLowerCase()
    return memberRole === 'student'
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

  async function toggleRoomJoining() {
    const room = selectedRoom.value
    if (!room?.id || roomJoiningSaving.value) return

    const accepting = Boolean(room.joins_closed_at)

    roomJoiningSaving.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const { data } = await services.setRoomJoining(room.id, accepting)
      const closedAt = data.joins_closed_at ?? null
      room.joins_closed_at = closedAt
      const listed = rooms.value.find((item) => item.id === room.id)
      if (listed) listed.joins_closed_at = closedAt
      roomMessage.value = accepting
        ? `${room.name} is accepting new students again. Students with the code can join.`
        : `${room.name} is closed to new students. The students already in it stay.`
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to change whether this room accepts new students.')
    } finally {
      roomJoiningSaving.value = false
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
    questionHasAnswer,
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
    roomJoiningSaving,
    toggleRoomJoining,
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
