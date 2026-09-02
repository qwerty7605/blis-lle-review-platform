import { computed, onMounted, reactive, ref } from 'vue'
import { questionsApi, reviewBotAdminApi } from '@/api'
import { LIBRARY_SUBJECT_CATEGORIES } from '@/constants/librarySubjects'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function asList(value) {
  return Array.isArray(value) ? value : []
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

  const form = reactive({
    subject: LIBRARY_SUBJECT_CATEGORIES[0] ?? '',
    topic: '',
    subtopic: '',
    sourceLimit: 5,
  })

  const generating = ref(false)
  const generateError = ref('')
  const generateMessage = ref('')

  const questions = ref([])
  const questionsLoading = ref(false)
  const questionsError = ref('')
  const statusFilter = ref('draft') // 'draft' | 'published' | 'all'
  const selectedIds = ref([])

  const editing = reactive({})
  const savingQuestionId = ref(null)

  const subjectOptions = computed(() => LIBRARY_SUBJECT_CATEGORIES.filter((subject) => (
    Object.prototype.hasOwnProperty.call(curatedTopics.value, subject)
  )))

  const topicOptions = computed(() => Object.keys(curatedTopics.value[form.subject] ?? {}))

  const subtopicOptions = computed(() => (
    curatedTopics.value[form.subject]?.[form.topic] ?? []
  ))

  const filteredQuestions = computed(() => (
    statusFilter.value === 'all'
      ? questions.value
      : questions.value.filter((question) => question.status === statusFilter.value)
  ))

  const allFilteredSelected = computed(() => (
    filteredQuestions.value.length > 0
    && filteredQuestions.value.every((question) => selectedIds.value.includes(question.id))
  ))

  function selectSubject(subject) {
    form.subject = subject
    form.topic = ''
    form.subtopic = ''
  }

  function selectTopic(topic) {
    form.topic = topic
    form.subtopic = ''
  }

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

  async function loadQuestions() {
    questionsLoading.value = true
    questionsError.value = ''

    try {
      const { data } = await reviewBotAdminApi.listQuestions({ subject: form.subject || undefined })
      questions.value = asList(data?.questions).map(normalizeQuestion)
      selectedIds.value = selectedIds.value.filter((id) => questions.value.some((question) => question.id === id))
    } catch (error) {
      questionsError.value = firstApiError(error, 'Unable to load Review Bot questions.')
    } finally {
      questionsLoading.value = false
    }
  }

  async function generate() {
    generateError.value = ''
    generateMessage.value = ''

    if (!form.subject) {
      generateError.value = 'Choose a subject first.'
      return
    }

    generating.value = true

    try {
      const { data } = await reviewBotAdminApi.generate({
        subject: form.subject,
        topic: form.topic || undefined,
        subtopic: form.subtopic || undefined,
        source_limit: Number(form.sourceLimit),
      })

      generateMessage.value = data?.message ?? 'Generation queued.'

      if (data?.status === 'queued') {
        setTimeout(loadQuestions, 4000)
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
      options: question.options.map((option) => ({ id: option.id, text: option.text, isCorrect: option.isCorrect })),
    }
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
    } catch (error) {
      questionsError.value = firstApiError(error, 'Unable to publish the selected questions.')
    }
  }

  async function unpublishSelected() {
    if (selectedIds.value.length === 0) return

    questionsError.value = ''

    try {
      await reviewBotAdminApi.unpublish(selectedIds.value)
      selectedIds.value = []
      await loadQuestions()
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
    await loadCuratedTopics()
    await loadQuestions()
  })

  return {
    curatedTopics,
    catalogLoading,
    catalogError,
    form,
    subjectOptions,
    topicOptions,
    subtopicOptions,
    generating,
    generateError,
    generateMessage,
    questions,
    filteredQuestions,
    questionsLoading,
    questionsError,
    statusFilter,
    selectedIds,
    allFilteredSelected,
    editing,
    savingQuestionId,
    selectSubject,
    selectTopic,
    loadQuestions,
    generate,
    toggleSelected,
    toggleSelectAllFiltered,
    startEditing,
    cancelEditing,
    setEditingCorrectOption,
    saveEditing,
    publishSelected,
    unpublishSelected,
    deleteQuestion,
  }
}
