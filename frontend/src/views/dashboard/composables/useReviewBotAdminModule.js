import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { questionsApi, reviewBotAdminApi } from '@/api'
import { useLiveRefresh } from '@/utils/liveRefresh'

// Generation runs in a background queue job. It is quick once it starts, but
// it shares one worker with topic tagging, which is not, so a run can sit in
// the queue for minutes first. While the server says a run for the open file
// is queued or running, the draft list is read again at this cadence:
// self-rescheduling rather than setInterval so a slow request never overlaps
// the next one. The first look comes sooner, because an unqueued run is
// usually done within a couple of seconds. The cap is only a backstop: the
// server stops saying "generating" by itself after ten minutes at most.
const GENERATION_FIRST_CHECK_MS = 2000
const GENERATION_POLL_INTERVAL_MS = 4000
const GENERATION_POLL_MAX_MS = 15 * 60 * 1000

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
    // Named on an administrator's list, which has every teacher's files.
    ownerName: bank.creator?.name ?? '',
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
  // True while a run for the open file is queued or running. The server is
  // the one that knows (`generating`, sent with every draft list): the page
  // only repeats it, so the wait is the same whether the run was started by
  // this click, by an earlier one, or before the page was left and reopened.
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
  // How many questions this file has in each status, for the tab counts.
  const statusCounts = ref({ draft: 0, published: 0, rejected: 0 })
  // What the last bulk action did, e.g. "12 questions published."
  const actionMessage = ref('')

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

  // Each bulk action applies only to the selected questions it can change:
  // a published question cannot be published again, and a declined one comes
  // back as a draft first. The buttons count these, not the whole selection.
  const selectedByStatus = computed(() => {
    const groups = { draft: [], published: [], rejected: [] }

    for (const question of filteredQuestions.value) {
      if (selectedIds.value.includes(question.id)) groups[question.status]?.push(question.id)
    }

    return groups
  })

  const publishableIds = computed(() => selectedByStatus.value.draft)
  const unpublishableIds = computed(() => selectedByStatus.value.published)
  const declinableIds = computed(() => [...selectedByStatus.value.draft, ...selectedByStatus.value.published])
  const restorableIds = computed(() => selectedByStatus.value.rejected)

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

  // silent: the quiet reload used while a run is being waited for and when
  // the teacher comes back to the page. No loading state, which would take
  // the list away from under a draft being read or edited, and a failure
  // kept to itself.
  async function loadQuestions({ silent = false } = {}) {
    if (!selectedBankId.value) {
      questions.value = []
      return
    }

    const bankId = selectedBankId.value
    const sentAt = Date.now()

    if (!silent) {
      questionsLoading.value = true
      questionsError.value = ''
    }

    try {
      const { data } = await reviewBotAdminApi.listQuestions({
        source_bank_id: bankId,
        // The endpoint hides declined drafts by default so they do not sit in
        // the review queue; seeing them again is an explicit request.
        ...(statusFilter.value === 'rejected' ? { status: 'rejected' } : {}),
      })

      // Another file was picked while this was on its way.
      if (selectedBankId.value !== bankId) return

      questions.value = asList(data?.questions).map(normalizeQuestion)
      if (data?.counts) statusCounts.value = { ...statusCounts.value, ...data.counts }
      selectedIds.value = selectedIds.value.filter((id) => questions.value.some((question) => question.id === id))

      noteGenerationState(Boolean(data?.generating), sentAt)
    } catch (error) {
      if (!silent) questionsError.value = firstApiError(error, 'Unable to load Review Bot questions.')
    } finally {
      if (!silent) questionsLoading.value = false
      scheduleGenerationCheck()
    }
  }

  /*
   * Waiting for a run to finish.
   *
   * This used to be guessed from the list: the wait began only when this
   * page's own click was answered "queued", and ended the first time the
   * list grew. Three ways that left the teacher looking at a list that
   * needed a reload:
   *
   *   - Drafts are saved a batch at a time. A look that landed mid-run saw
   *     the first batches, called the run finished, and never looked again.
   *   - A click answered "already being generated" started no wait at all,
   *     under a message promising the drafts would appear shortly.
   *   - The wait lived in this page. Leave it, or pick another file, and
   *     come back before the run was done: nothing was waiting any more.
   *
   * Now the server says whether a run for the file is queued or running,
   * with every list it sends, and the list is read again for exactly as long
   * as it says so.
   */
  // How many questions the list held when a run was started from this page;
  // null when it was not, or when the list was showing declined drafts.
  let draftsBeforeRun = null
  // When the request that started (or joined) a run was sent. A list asked
  // for before that cannot know about the run, and its "not generating" must
  // not end the wait.
  let waitingSince = 0
  let generationCheckTimeoutId = null
  let generationWaitDeadline = 0

  function stopGenerationCheck() {
    if (generationCheckTimeoutId !== null) {
      clearTimeout(generationCheckTimeoutId)
      generationCheckTimeoutId = null
    }
  }

  function scheduleGenerationCheck(delay = GENERATION_POLL_INTERVAL_MS) {
    stopGenerationCheck()

    if (!generationPending.value) return

    if (Date.now() >= generationWaitDeadline) {
      generationPending.value = false
      return
    }

    generationCheckTimeoutId = setTimeout(() => {
      generationCheckTimeoutId = null
      // Ends by scheduling the next look, if there is still a run to wait for.
      loadQuestions({ silent: true })
    }, delay)
  }

  function startWaitingForGeneration() {
    if (!generationPending.value) generationWaitDeadline = Date.now() + GENERATION_POLL_MAX_MS
    generationPending.value = true
  }

  function noteGenerationState(serverSaysGenerating, askedAt) {
    if (serverSaysGenerating) {
      startWaitingForGeneration()
      return
    }

    if (!generationPending.value || askedAt < waitingSince) return

    // The run this page was waiting for is over. Say what came of it, in
    // place of the "they will appear shortly" it was started with.
    generationPending.value = false

    if (draftsBeforeRun === null || statusFilter.value === 'rejected') {
      generateMessage.value = 'Review questions for this file have finished generating.'
    } else {
      const added = questions.value.length - draftsBeforeRun

      generateMessage.value = added > 0
        ? `Done. ${added} new review question${added === 1 ? '' : 's'} added as ${added === 1 ? 'a draft' : 'drafts'}.`
        : 'Done, with nothing new to add: every item in this file already has review questions, or the new ones were too close to ones already here.'
    }

    draftsBeforeRun = null
  }

  onUnmounted(stopGenerationCheck)

  // Library → Select Exam File: pick a file and load both its original
  // questions and whatever review batch already exists for it. That's the
  // only choice a teacher makes -- no topic/subtopic/count picking beyond
  // this, see generate() below.
  async function selectBank(bankId) {
    stopGenerationCheck()
    generationPending.value = false
    draftsBeforeRun = null
    selectedBankId.value = Number(bankId)
    coverage.value = []
    coverageOpen.value = false
    generateError.value = ''
    generateMessage.value = ''
    actionMessage.value = ''
    selectedIds.value = []
    statusCounts.value = { draft: 0, published: 0, rejected: 0 }

    // If a run for this file is still going, loadQuestions() hears so from
    // the server and picks the wait back up.
    await Promise.all([loadSourceQuestions(), loadQuestions()])
  }

  function changeFile() {
    stopGenerationCheck()
    generationPending.value = false
    draftsBeforeRun = null
    selectedBankId.value = null
    sourceQuestions.value = []
    questions.value = []
    selectedIds.value = []
    generateError.value = ''
    generateMessage.value = ''
    actionMessage.value = ''
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
    const sentAt = Date.now()

    try {
      const { data } = await reviewBotAdminApi.generate({
        source_bank_id: selectedBankId.value,
      })

      generateMessage.value = data?.message ?? 'Generation queued.'

      // 'queued': this click started a run. 'in_progress': one was already
      // going. Either way drafts are on their way, so either way the list is
      // watched until the server says the run is over.
      if (['queued', 'in_progress'].includes(data?.status)) {
        if (!generationPending.value) {
          draftsBeforeRun = statusFilter.value === 'rejected' ? null : questions.value.length
        }

        waitingSince = sentAt
        startWaitingForGeneration()
        scheduleGenerationCheck(GENERATION_FIRST_CHECK_MS)
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

  // One bulk action: send the ids it applies to, reload, and say what moved
  // -- the questions leave the tab being looked at, so without the message a
  // publish looked the same as nothing happening.
  async function runBulkAction(ids, send, describe, failure) {
    if (ids.length === 0) return

    const count = ids.length
    questionsError.value = ''
    actionMessage.value = ''

    try {
      await send(ids)
      selectedIds.value = []
      await loadQuestions()
      actionMessage.value = describe(`${count} question${count === 1 ? '' : 's'}`)
      // These all move an item between covered and not, so a stale panel
      // would contradict the list right next to it.
      if (coverageOpen.value) await loadCoverage()
    } catch (error) {
      questionsError.value = firstApiError(error, failure)
    }
  }

  const publishSelected = () => runBulkAction(
    publishableIds.value,
    reviewBotAdminApi.publish,
    (what) => `${what} published. Students can now practise with ${what.startsWith('1 ') ? 'it' : 'them'}.`,
    'Unable to publish the selected questions.',
  )

  const rejectSelected = () => runBulkAction(
    declinableIds.value,
    reviewBotAdminApi.reject,
    (what) => `${what} declined. You can restore ${what.startsWith('1 ') ? 'it' : 'them'} from the Declined tab.`,
    'Unable to decline the selected questions.',
  )

  const unpublishSelected = () => runBulkAction(
    unpublishableIds.value,
    reviewBotAdminApi.unpublish,
    (what) => `${what} unpublished and moved back to Draft.`,
    'Unable to unpublish the selected questions.',
  )

  // The same request as unpublishing: both put a question back to a draft.
  const restoreSelected = () => runBulkAction(
    restorableIds.value,
    reviewBotAdminApi.unpublish,
    (what) => `${what} restored to Draft.`,
    'Unable to restore the selected questions.',
  )

  // Switching into or out of the declined view changes what the server sends,
  // so that one crossing has to refetch. Draft/published/all are filtered from
  // the same payload and must not.
  watch(statusFilter, (next, previous) => {
    // A selection made on one tab must not be acted on from another, where
    // those questions are no longer on screen.
    selectedIds.value = []

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

  // Coming back to the page: the open file's drafts are read again quietly,
  // or, with no file open, the list of files (a set uploaded in the Library
  // in another tab is here without a reload).
  useLiveRefresh(async () => {
    if (selectedBankId.value) {
      await loadQuestions({ silent: true })
      return
    }

    const { data } = await questionsApi.listBanks()
    libraryBanks.value = asList(data?.banks).map(normalizeBank)
  }, {
    when: () => !questionsLoading.value
      && !libraryBanksLoading.value
      && !generating.value
      && savingQuestionId.value === null,
  })

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
    statusCounts,
    actionMessage,
    publishableIds,
    unpublishableIds,
    declinableIds,
    restorableIds,
    restoreSelected,
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
