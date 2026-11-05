import {
  computed,
  onBeforeUnmount,
  reactive,
  ref,
} from 'vue'
import { formatClockTime, normalizeExamDeliveryMode } from './useDashboardFormatters'
import {
  firstApiError,
} from './roomsHelpers'
import { useFieldChecks, wholeNumberProblem } from '@/utils/fieldChecks'

/**
 * The live exam board a teacher opens from a room: who has started, how far
 * each student is, and each question's answers, refreshed every 5 seconds
 * while it is open. Part of useRoomsModule.
 */
export function useRoomLiveBoard({ canViewExamResults, roomError, rooms, selectedRoom, selectedRoomId, services }) {
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

  let liveBoardRefreshInterval

  // Extra time (ExamController::giveExtraTime / giveRoomExtraTime): for one
  // student whose attempt is under way (row), or for everyone taking the
  // exam in this room (row null). Only their deadlines move.
  const extraTimeDialog = reactive({ open: false, row: null, label: '', minutes: 10, reason: '', saving: false, error: '' })
  const extraTimeChecks = useFieldChecks('extra-time', {
    minutes: () => wholeNumberProblem(extraTimeDialog.minutes, { label: 'Minutes', min: 1, max: 120 }),
  })
  const liveBoardNotice = ref('')

  function isTakingNow(row) {
    return row?.attempt?.status === 'in_progress' && Number(row.attempt.remaining_seconds ?? 1) > 0
  }

  const liveBoardTakingNow = computed(() => liveBoardRows.value.filter(isTakingNow).length)

  function liveBoardTimeLeft(row) {
    const seconds = Number(row?.attempt?.remaining_seconds)
    if (!Number.isFinite(seconds)) return ''
    if (seconds < 60) return 'under a minute left'

    const minutes = Math.ceil(seconds / 60)
    if (minutes < 60) return `${minutes} min left`
    if (minutes < 24 * 60) return `${Math.floor(minutes / 60)} h ${minutes % 60} min left`

    const days = Math.floor(minutes / (24 * 60))
    return `${days} ${days === 1 ? 'day' : 'days'} left`
  }

  function openExtraTime(row = null, label = '') {
    extraTimeDialog.open = true
    extraTimeDialog.row = row
    extraTimeDialog.label = label
    extraTimeDialog.minutes = 10
    extraTimeDialog.reason = ''
    extraTimeDialog.error = ''
    extraTimeChecks.reset()
  }

  function closeExtraTime() {
    extraTimeDialog.open = false
    extraTimeDialog.row = null
  }

  async function saveExtraTime() {
    if (extraTimeDialog.saving || !liveBoardExam.value?.id) return
    if (!extraTimeChecks.check()) return

    extraTimeDialog.saving = true
    extraTimeDialog.error = ''

    const payload = { minutes: Number(extraTimeDialog.minutes), reason: extraTimeDialog.reason.trim() || null }

    try {
      const { data } = extraTimeDialog.row
        ? await services.giveExtraTime(liveBoardExam.value.id, extraTimeDialog.row.attempt.id, payload)
        : await services.giveRoomExtraTime(liveBoardExam.value.id, { ...payload, room_id: liveBoardRoomId.value })

      liveBoardNotice.value = data?.message ?? 'Time added.'
      closeExtraTime()
      await loadLiveBoard(true)
    } catch (error) {
      if (!extraTimeChecks.takeServerErrors(error)) extraTimeDialog.error = firstApiError(error, 'Unable to add time.')
    } finally {
      extraTimeDialog.saving = false
    }
  }

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

  function resetLiveBoardState() {
    liveBoardRows.value = []
    liveBoardItemSummary.value = []
    liveBoardSummary.value = {
      students_total: 0,
      attempts_started: 0,
      attempts_submitted: 0,
    }
    liveBoardError.value = ''
    liveBoardNotice.value = ''
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

  onBeforeUnmount(() => {
    stopLiveBoardAutoRefresh()
  })

  return {
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
    extraTimeDialog,
    extraTimeChecks,
    liveBoardNotice,
    liveBoardTakingNow,
    isTakingNow,
    liveBoardTimeLeft,
    openExtraTime,
    closeExtraTime,
    saveExtraTime,
  }
}
