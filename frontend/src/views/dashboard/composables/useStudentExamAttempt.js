import {
  computed,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from 'vue'
import { normalizeExamDeliveryMode } from './useDashboardFormatters'
import {
  archivedExamAction,
  canStudentOpenExam,
  isStudentExamCompleted,
  isStudentExamRetakeLimitReached,
  studentMaxAttempts,
  studentSubmittedAttemptId,
} from './studentExamAvailability'
import {
  EXAM_PHONE_VIEWPORT_QUERY,
  EXAM_STATUS_CHECK_INTERVAL_MS,
  EXAM_TIME_UP_TRIES,
  firstApiError,
  splitQuestionStemAndNumberedItems,
} from './roomsHelpers'

/**
 * A student taking an exam inside the Rooms page: the attempt, its questions
 * and answers, the timer and the server check every 30 seconds, moving
 * between questions, the review screen and submitting, and the review of a
 * submitted attempt. Part of useRoomsModule.
 */
export function useStudentExamAttempt({
  roomMessage,
  route,
  router,
  selectedRoom,
  selectedRoomActiveExams,
  selectedRoomArchivedExams,
  selectedRoomId,
  services,
}) {
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
  let studentExamSyncing = false

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

  onMounted(() => {
    if (typeof window !== 'undefined') {
      examAttemptMobileMediaQuery = window.matchMedia(EXAM_PHONE_VIEWPORT_QUERY)
      syncExamAttemptSidebarForViewport(examAttemptMobileMediaQuery)

      if (typeof examAttemptMobileMediaQuery.addEventListener === 'function') {
        examAttemptMobileMediaQuery.addEventListener('change', syncExamAttemptSidebarForViewport)
      } else {
        examAttemptMobileMediaQuery.addListener(syncExamAttemptSidebarForViewport)
      }
    }
  })

  onBeforeUnmount(() => {
    clearStudentExamTimer()
    stopStudentExamAutoSync()

    if (!examAttemptMobileMediaQuery) return

    if (typeof examAttemptMobileMediaQuery.removeEventListener === 'function') {
      examAttemptMobileMediaQuery.removeEventListener('change', syncExamAttemptSidebarForViewport)
    } else {
      examAttemptMobileMediaQuery.removeListener(syncExamAttemptSidebarForViewport)
    }
  })

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

    // Current is a ring drawn over whichever of these it is (is-current),
    // so the question being read still shows whether it is answered.
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
  }
}
