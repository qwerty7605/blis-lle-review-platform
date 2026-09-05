import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { questionsApi, reviewBotAdminApi } from '@/api'

// Generation runs in a background queue job and can legitimately take several
// minutes (it's a real OpenAI call per batch of source items) -- polled at
// this cadence, self-rescheduling rather than setInterval so a slow request
// never overlaps the next tick, and capped so a stuck/failed job doesn't
// poll forever in the background.
const GENERATION_POLL_INTERVAL_MS = 5000
const GENERATION_POLL_MAX_MS = 10 * 60 * 1000

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function asList(value) {
  return Array.isArray(value) ? value : []
}

function normalizeBank(bank) {
  return {
    id: Number(bank.id),
    title: bank.title ?? 'Untitled set',
    subject: bank.subject ?? '',
    sourceFilename: bank.source_filename ?? null,
    questionsCount: Number(bank.questions_count ?? bank.total_items ?? 0),
    mappingStatus: bank.mapping_status ?? 'idle',
    createdAt: bank.created_at ?? null,
  }
}

function normalizeSourceQuestion(question) {
  return {
    id: Number(question.id),
    itemNumber: question.item_number ?? null,
    questionText: question.question_text ?? '',
    questionType: question.question_type ?? 'multiple_choice',
    topic: question.topic?.name ?? null,
    answerText: question.answer_text ?? '',
    options: asList(question.options).map((option) => ({
      id: Number(option.id),
      label: option.option_label,
      text: option.option_text,
      isCorrect: Boolean(option.is_correct),
    })),
  }
}

function normalizeQuestion(question) {
  return {
    id: Number(question.id),
    subject: question.subject ?? '',
    topic: question.topic?.name ?? null,
    subtopic: question.subtopic?.name ?? null,
    difficulty: question.difficulty ?? 'medium',
    cognitiveLevel: question.cognitive_level ?? null,
    status: question.status ?? 'draft',
    questionText: question.question_text ?? '',
    explanation: question.explanation ?? '',
    sourceReference: question.source_reference ?? null,
    sourceItemNumber: question.source_question?.item_number ?? null,
    sourceBankTitle: question.source_question?.question_bank?.title ?? null,
    createdAt: question.created_at ?? null,
    options: asList(question.options).map((option) => ({
      id: Number(option.id),
      label: option.option_label,
      text: option.option_text,
      isCorrect: Boolean(option.is_correct),
    })),
  }
}

export function useReviewBotAdminModule() {
  const curatedTopics = ref({}) // subject -> topic -> [subtopics]
  const catalogLoading = ref(false)
  const catalogError = ref('')

  // Step 1: Library — the teacher's uploaded exam-question files. This is the
  // single source of truth for exam questions; nothing here is uploaded
  // specifically for the Review Bot (see LibraryView.vue for actual uploads).
  const libraryBanks = ref([])
  const libraryBanksLoading = ref(false)
  const libraryBanksError = ref('')
  const selectedBankId = ref(null)

  // Step 2: Preview — the selected file's original exam questions, read-only.
  const sourceQuestions = ref([])
  const sourceQuestionsLoading = ref(false)
  const sourceQuestionsError = ref('')

  const generating = ref(false)
  const generateError = ref('')
  const generateMessage = ref('')
  // True from the moment generation is queued until new drafts actually show
  // up (or the poll gives up) -- lets the template show "generating..."
  // instead of a bare "no questions yet" empty state while the background
  // job is still running, so the teacher never has to guess and hit Refresh.
  const generationPending = ref(false)

  // Step 3/4: Generate → Review/Edit → Map/Assign — the AI-generated review
  // questions for the selected file, kept clearly separate from the original
  // exam questions in `sourceQuestions` above.
  const questions = ref([])
  const questionsLoading = ref(false)
  const questionsError = ref('')
  const statusFilter = ref('draft') // 'draft' | 'published' | 'all' | 'rejected'

  // Coverage is loaded on demand rather than with the question list: it walks
  // every source item in the file, and most visits never open it.
  const coverage = ref([])
  const coverageLoading = ref(false)
  const coverageError = ref('')
  const coverageOpen = ref(false)
  const selectedIds = ref([])

  const editing = reactive({})
  const savingQuestionId = ref(null)

  const selectedBank = computed(() => libraryBanks.value.find((bank) => bank.id === selectedBankId.value) ?? null)

  // Only used to populate each draft question's "map to topic/subtopic"
  // dropdowns during Review/Edit -- generation itself takes no topic/subtopic
  // input, see generate() below.
  const topicOptions = computed(() => Object.keys(curatedTopics.value[selectedBank.value?.subject] ?? {}))

  const filteredQuestions = computed(() => (
    // 'all' and 'rejected' are both resolved server-side -- 'all' already
    // excludes declined drafts, and 'rejected' returns only those.
    ['all', 'rejected'].includes(statusFilter.value)
      ? questions.value
      : questions.value.filter((question) => question.status === statusFilter.value)
  ))

  const allFilteredSelected = computed(() => (
    filteredQuestions.value.length > 0
    && filteredQuestions.value.every((question) => selectedIds.value.includes(question.id))
  ))

  async function loadCuratedTopics() {
    catalogLoading.value = true
    catalogError.value = ''

    try {
      const { data } = await questionsApi.listTopics()
      curatedTopics.value = data?.curated_topics ?? {}
    } catch (error) {
      catalogError.value = firstApiError(error, 'Unable to load the curated topic list.')
    } finally {
      catalogLoading.value = false
    }
  }

  async function loadLibraryBanks() {
    libraryBanksLoading.value = true
    libraryBanksError.value = ''

    try {
      const { data } = await questionsApi.listBanks()
      libraryBanks.value = asList(data?.banks).map(normalizeBank)
    } catch (error) {
      libraryBanksError.value = firstApiError(error, 'Unable to load your Library files.')
    } finally {
      libraryBanksLoading.value = false
    }
  }

  async function loadSourceQuestions() {
    if (!selectedBankId.value) {
      sourceQuestions.value = []
      return
    }

    sourceQuestionsLoading.value = true
    sourceQuestionsError.value = ''

    try {
      const { data } = await questionsApi.listBankQuestions(selectedBankId.value)
      sourceQuestions.value = asList(data?.questions).map(normalizeSourceQuestion)
    } catch (error) {
      sourceQuestionsError.value = firstApiError(error, 'Unable to load this file\'s questions.')
    } finally {
      sourceQuestionsLoading.value = false
    }
  }

  async function loadQuestions() {
    if (!selectedBankId.value) {
      questions.value = []
      return
    }

    questionsLoading.value = true
    questionsError.value = ''

    try {
      const { data } = await reviewBotAdminApi.listQuestions({
        source_bank_id: selectedBankId.value,
        // The endpoint hides declined drafts by default so they do not sit in
        // the review queue; seeing them again is an explicit request.
        ...(statusFilter.value === 'rejected' ? { status: 'rejected' } : {}),
      })
      questions.value = asList(data?.questions).map(normalizeQuestion)
      selectedIds.value = selectedIds.value.filter((id) => questions.value.some((question) => question.id === id))

      // New drafts actually landed -- the thing generationPending was
      // waiting for. Stops the poll below on its own, whether this load was
      // triggered by a poll tick or a manual Refresh click.
      if (generationPending.value && questions.value.length > generationBaselineCount) {
        generationPending.value = false
      }
    } catch (error) {
      questionsError.value = firstApiError(error, 'Unable to load Review Bot questions.')
    } finally {
      questionsLoading.value = false
    }
  }

  // Self-rescheduling poll (not setInterval, so a slow request never overlaps
  // the next tick) that keeps checking for the generated batch while
  // generationPending is true, and gives up after GENERATION_POLL_MAX_MS so a
  // stuck/failed job doesn't poll forever in the background.
  let generationBaselineCount = 0
  let generationPollTimeoutId = null
  let generationPollDeadline = 0

  function stopGenerationPoll() {
    if (generationPollTimeoutId !== null) {
      clearTimeout(generationPollTimeoutId)
      generationPollTimeoutId = null
    }
  }

  function scheduleGenerationPollIfNeeded() {
    stopGenerationPoll()

    if (!generationPending.value) return

    if (Date.now() >= generationPollDeadline) {
      generationPending.value = false
      return
    }

    generationPollTimeoutId = setTimeout(async () => {
      generationPollTimeoutId = null
      await loadQuestions()
      scheduleGenerationPollIfNeeded()
    }, GENERATION_POLL_INTERVAL_MS)
  }

  onUnmounted(stopGenerationPoll)

  // Library → Select Exam File: pick a file and load both its original
  // questions and whatever review batch already exists for it. That's the
  // only choice a teacher makes -- no topic/subtopic/count picking beyond
  // this, see generate() below.
  async function selectBank(bankId) {
    stopGenerationPoll()
    generationPending.value = false
    selectedBankId.value = Number(bankId)
    coverage.value = []
    coverageOpen.value = false
    generateError.value = ''
    generateMessage.value = ''
    selectedIds.value = []

    await Promise.all([loadSourceQuestions(), loadQuestions()])
  }

  function changeFile() {
    stopGenerationPoll()
    generationPending.value = false
    selectedBankId.value = null
    sourceQuestions.value = []
    questions.value = []
    selectedIds.value = []
    generateError.value = ''
    generateMessage.value = ''
  }

  // Generate Review Exam: one click, no setup. The backend reads the whole
  // file -- every question's subject/topic/subtopic it already knows from
  // Library mapping -- and generates fresh, original review questions
  // covering most of the file automatically (see ReviewBotAdminService for
  // the exact coverage and why the source wording is never reused as-is).
  async function generate() {
    generateError.value = ''
    generateMessage.value = ''

    if (!selectedBankId.value) {
      generateError.value = 'Select a Library file first.'
      return
    }

    generating.value = true

    try {
      const { data } = await reviewBotAdminApi.generate({
        source_bank_id: selectedBankId.value,
      })

      generateMessage.value = data?.message ?? 'Generation queued.'

      if (data?.status === 'queued') {
        // A real background OpenAI call, not a fixed few seconds -- keep
        // checking until the drafts actually land instead of a single
        // reload that's very likely to run before the job finishes.
        generationBaselineCount = questions.value.length
        generationPollDeadline = Date.now() + GENERATION_POLL_MAX_MS
        generationPending.value = true
        scheduleGenerationPollIfNeeded()
      }
    } catch (error) {
      generateError.value = firstApiError(error, 'Unable to queue Review Bot question generation.')
    } finally {
      generating.value = false
    }
  }

  function toggleSelected(questionId) {
    selectedIds.value = selectedIds.value.includes(questionId)
      ? selectedIds.value.filter((id) => id !== questionId)
      : [...selectedIds.value, questionId]
  }

  function toggleSelectAllFiltered() {
    if (allFilteredSelected.value) {
      const filteredIds = new Set(filteredQuestions.value.map((question) => question.id))
      selectedIds.value = selectedIds.value.filter((id) => !filteredIds.has(id))
    } else {
      selectedIds.value = [...new Set([...selectedIds.value, ...filteredQuestions.value.map((question) => question.id)])]
    }
  }

  function startEditing(question) {
    editing[question.id] = {
      questionText: question.questionText,
      explanation: question.explanation,
      difficulty: question.difficulty,
      // Map/assign: pre-filled with what the question inherited from its
      // source item, editable here before publishing.
      topicName: question.topic ?? '',
      subtopicName: question.subtopic ?? '',
      options: question.options.map((option) => ({ id: option.id, text: option.text, isCorrect: option.isCorrect })),
    }
  }

  function setEditingTopic(questionId, topic) {
    const draft = editing[questionId]
    if (!draft) return
    draft.topicName = topic
    draft.subtopicName = ''
  }

  function cancelEditing(questionId) {
    delete editing[questionId]
  }

  function setEditingCorrectOption(questionId, optionId) {
    const draft = editing[questionId]
    if (!draft) return
    draft.options = draft.options.map((option) => ({ ...option, isCorrect: option.id === optionId }))
  }

  async function saveEditing(questionId) {
    const draft = editing[questionId]
    if (!draft) return

    savingQuestionId.value = questionId
    questionsError.value = ''

    try {
      const { data } = await reviewBotAdminApi.updateQuestion(questionId, {
        question_text: draft.questionText,
        explanation: draft.explanation,
        difficulty: draft.difficulty,
        topic_name: draft.topicName || undefined,
        subtopic_name: draft.topicName ? (draft.subtopicName || '') : undefined,
        options: draft.options.map((option) => ({ id: option.id, text: option.text, is_correct: option.isCorrect })),
      })

      const updated = normalizeQuestion(data?.question ?? {})
      questions.value = questions.value.map((question) => (question.id === questionId ? updated : question))
      delete editing[questionId]
    } catch (error) {
      questionsError.value = firstApiError(error, 'Unable to save this question.')
    } finally {
      savingQuestionId.value = null
    }
  }

  async function publishSelected() {
    if (selectedIds.value.length === 0) return

    questionsError.value = ''

    try {
      await reviewBotAdminApi.publish(selectedIds.value)
      selectedIds.value = []
      await loadQuestions()
      // These all move an item between covered and not, so a stale panel
      // would contradict the list right next to it.
      if (coverageOpen.value) await loadCoverage()
    } catch (error) {
      questionsError.value = firstApiError(error, 'Unable to publish the selected questions.')
    }
  }

  // Switching into or out of the declined view changes what the server sends,
  // so that one crossing has to refetch. Draft/published/all are filtered from
  // the same payload and must not.
  watch(statusFilter, (next, previous) => {
    if (!selectedBankId.value) return
    if (next === 'rejected' || previous === 'rejected') loadQuestions()
  })

  const coverageSummary = computed(() => {
    const rows = coverage.value

    return {
      total: rows.length,
      complete: rows.filter((row) => row.missing_levels.length === 0).length,
      empty: rows.filter((row) => row.total === 0).length,
      partial: rows.filter((row) => row.total > 0 && row.missing_levels.length > 0).length,
    }
  })

  async function loadCoverage() {
    const subject = selectedBank.value?.subject

    if (!selectedBankId.value || !subject) return

    coverageLoading.value = true
    coverageError.value = ''

    try {
      const { data } = await reviewBotAdminApi.coverage({
        subject,
        source_bank_id: selectedBankId.value,
      })
      coverage.value = asList(data?.coverage)
    } catch (error) {
      coverageError.value = firstApiError(error, 'Unable to load coverage for this file.')
      coverage.value = []
    } finally {
      coverageLoading.value = false
    }
  }

  async function toggleCoverage() {
    coverageOpen.value = !coverageOpen.value

    if (coverageOpen.value && coverage.value.length === 0) {
      await loadCoverage()
    }
  }

  async function rejectSelected() {
    if (selectedIds.value.length === 0) return

    questionsError.value = ''

    try {
      await reviewBotAdminApi.reject(selectedIds.value)
      selectedIds.value = []
      await loadQuestions()
      // These all move an item between covered and not, so a stale panel
      // would contradict the list right next to it.
      if (coverageOpen.value) await loadCoverage()
    } catch (error) {
      questionsError.value = firstApiError(error, 'Unable to decline the selected questions.')
    }
  }

  async function unpublishSelected() {
    if (selectedIds.value.length === 0) return

    questionsError.value = ''

    try {
      await reviewBotAdminApi.unpublish(selectedIds.value)
      selectedIds.value = []
      await loadQuestions()
      // These all move an item between covered and not, so a stale panel
      // would contradict the list right next to it.
      if (coverageOpen.value) await loadCoverage()
    } catch (error) {
      questionsError.value = firstApiError(error, 'Unable to unpublish the selected questions.')
    }
  }

  async function deleteQuestion(questionId) {
    questionsError.value = ''

    try {
      await reviewBotAdminApi.deleteQuestion(questionId)
      questions.value = questions.value.filter((question) => question.id !== questionId)
      selectedIds.value = selectedIds.value.filter((id) => id !== questionId)
    } catch (error) {
      questionsError.value = firstApiError(error, 'Unable to delete this question.')
    }
  }

  onMounted(async () => {
    await Promise.all([loadCuratedTopics(), loadLibraryBanks()])
  })

  return {
    curatedTopics,
    catalogLoading,
    catalogError,
    libraryBanks,
    libraryBanksLoading,
    libraryBanksError,
    selectedBankId,
    selectedBank,
    sourceQuestions,
    sourceQuestionsLoading,
    sourceQuestionsError,
    topicOptions,
    generating,
    generateError,
    generateMessage,
    generationPending,
    questions,
    filteredQuestions,
    questionsLoading,
    questionsError,
    statusFilter,
    selectedIds,
    allFilteredSelected,
    editing,
    savingQuestionId,
    loadLibraryBanks,
    selectBank,
    changeFile,
    loadQuestions,
    generate,
    toggleSelected,
    toggleSelectAllFiltered,
    startEditing,
    setEditingTopic,
    cancelEditing,
    setEditingCorrectOption,
    saveEditing,
    publishSelected,
    rejectSelected,
    coverage,
    coverageLoading,
    coverageError,
    coverageOpen,
    coverageSummary,
    loadCoverage,
    toggleCoverage,
    unpublishSelected,
    deleteQuestion,
  }
}
