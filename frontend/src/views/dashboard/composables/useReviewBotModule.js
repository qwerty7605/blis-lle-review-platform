import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { reviewBotApi } from '@/api'
import { useLiveRefresh } from '@/utils/liveRefresh'

function firstApiError(error, fallbackMessage) {
  return error?.response?.data?.message ?? fallbackMessage
}

function asList(value) {
  return Array.isArray(value) ? value : []
}

function normalizeSubjectEntry(subject) {
  if (!subject || typeof subject !== 'object') return null

  const label = String(subject.subject ?? '').trim()
  if (!label) return null

  return {
    subject: label,
    description: String(subject.description ?? '').trim(),
    focusAreas: asList(subject.focus_areas).map((item) => String(item ?? '').trim()).filter(Boolean),
    questionCount: Number(subject.question_count ?? 0),
    botReady: Boolean(subject.bot_ready),
    topics: asList(subject.topics).map((topic) => ({
      id: Number(topic.id),
      name: String(topic.name ?? '').trim(),
      questionCount: Number(topic.question_count ?? 0),
      subtopics: asList(topic.subtopics).map((subtopic) => ({
        id: Number(subtopic.id),
        name: String(subtopic.name ?? '').trim(),
        questionCount: Number(subtopic.question_count ?? 0),
      })),
    })),
    difficulties: asList(subject.difficulties).map((value) => String(value ?? '').trim()).filter(Boolean),
  }
}

function normalizeWeakArea(weakArea) {
  if (!weakArea || typeof weakArea !== 'object' || Object.keys(weakArea).length === 0) return null

  return {
    basis: String(weakArea.basis ?? 'subject'),
    subject: weakArea.subject ?? null,
    subjectScore: weakArea.subject_score ?? null,
    topicId: weakArea.topic_id ?? null,
    topic: weakArea.topic ?? null,
    topicScore: weakArea.topic_score ?? null,
    subtopicId: weakArea.subtopic_id ?? null,
    subtopic: weakArea.subtopic ?? null,
    subtopicScore: weakArea.subtopic_score ?? null,
  }
}

function normalizeQuestion(question) {
  return {
    itemNumber: Number(question.item_number),
    questionId: Number(question.question_id),
    questionText: String(question.question_text ?? ''),
    questionType: String(question.question_type ?? 'multiple_choice'),
    options: asList(question.options).map((option) => ({
      id: Number(option.id),
      label: option.label,
      text: String(option.text ?? ''),
    })),
  }
}

export function useReviewBotModule() {
  const route = useRoute()

  const catalogLoading = ref(false)
  const catalogError = ref('')
  const subjects = ref([])
  const weakArea = ref(null)

  const mode = ref('manual') // 'manual' | 'weak_area'
  const form = reactive({
    subject: '',
    topicId: null,
    subtopicId: null,
    difficulty: '',
    questionCount: 10,
  })

  const sessionStarting = ref(false)
  const sessionError = ref('')
  const session = ref(null) // { attemptId, subject, topicId, subtopicId, questions }
  const answers = reactive({})
  const submitting = ref(false)
  const result = ref(null)

  const questionCountOptions = [5, 10, 15, 20]

  const selectedSubjectEntry = computed(() => (
    subjects.value.find((entry) => entry.subject === form.subject) ?? null
  ))

  const availableTopics = computed(() => selectedSubjectEntry.value?.topics ?? [])

  const selectedTopicEntry = computed(() => (
    availableTopics.value.find((topic) => topic.id === form.topicId) ?? null
  ))

  const availableSubtopics = computed(() => selectedTopicEntry.value?.subtopics ?? [])

  const availableDifficulties = computed(() => selectedSubjectEntry.value?.difficulties ?? [])

  const readySubjects = computed(() => subjects.value.filter((entry) => entry.botReady))

  const weakAreaLabel = computed(() => {
    const area = weakArea.value
    if (!area) return ''
    return [area.subject, area.topic, area.subtopic].filter(Boolean).join(' → ')
  })

  const weakAreaScoreText = computed(() => {
    const area = weakArea.value
    if (!area) return ''
    const score = area.subtopicScore ?? area.topicScore ?? area.subjectScore
    return score !== null && score !== undefined ? `${Number(score).toFixed(0)}%` : ''
  })

  const canStart = computed(() => {
    if (mode.value === 'weak_area') return Boolean(weakArea.value)
    return Boolean(form.subject) && Number(form.questionCount) >= 3 && Number(form.questionCount) <= 30
  })

  const hasSession = computed(() => Boolean(session.value))

  const answeredCount = computed(() => (
    session.value ? session.value.questions.filter((question) => answers[question.questionId] != null).length : 0
  ))

  const allAnswered = computed(() => (
    hasSession.value && session.value.questions.length > 0 && answeredCount.value === session.value.questions.length
  ))

  function selectSubject(subject) {
    form.subject = subject
    form.topicId = null
    form.subtopicId = null
    form.difficulty = ''
  }

  function selectTopic(topicId) {
    form.topicId = topicId || null
    form.subtopicId = null
  }

  function selectSubtopic(subtopicId) {
    form.subtopicId = subtopicId || null
  }

  function setDifficulty(value) {
    form.difficulty = value || ''
  }

  function setQuestionCount(count) {
    form.questionCount = Number(count)
  }

  function setMode(nextMode) {
    mode.value = nextMode === 'weak_area' ? 'weak_area' : 'manual'
  }

  function clearAnswers() {
    Object.keys(answers).forEach((key) => delete answers[key])
  }

  function resetSession() {
    session.value = null
    result.value = null
    clearAnswers()
  }

  // silent: the quiet refresh behind useLiveRefresh (below).
  async function loadCatalog({ silent = false } = {}) {
    if (!silent) {
      catalogLoading.value = true
      catalogError.value = ''
    }

    try {
      const { data } = await reviewBotApi.catalog()
      subjects.value = asList(data?.subjects).map(normalizeSubjectEntry).filter(Boolean)
      weakArea.value = normalizeWeakArea(data?.weak_area)
    } catch (error) {
      if (!silent) catalogError.value = firstApiError(error, 'Unable to load the Review Bot catalog.')
    } finally {
      if (!silent) catalogLoading.value = false
    }
  }

  // What can be practised changes when a teacher publishes questions. It is
  // read again when the student comes back to this page, but never in the
  // middle of a set.
  useLiveRefresh(() => loadCatalog({ silent: true }), {
    when: () => !catalogLoading.value && !sessionStarting.value && !session.value,
  })

  async function startSession() {
    if (!canStart.value) {
      sessionError.value = mode.value === 'weak_area'
        ? 'We need a bit more exam history before we can detect a weak area to review.'
        : 'Pick a subject and a valid question count first.'
      return
    }

    sessionStarting.value = true
    sessionError.value = ''
    resetSession()

    try {
      const payload = mode.value === 'weak_area'
        ? { use_weak_area: true, question_count: Number(form.questionCount) }
        : {
          subject: form.subject,
          topic_id: form.topicId || undefined,
          subtopic_id: form.subtopicId || undefined,
          difficulty: form.difficulty || undefined,
          question_count: Number(form.questionCount),
        }

      const { data } = await reviewBotApi.startSession(payload)

      session.value = {
        attemptId: Number(data?.attempt?.id),
        subject: data?.attempt?.subject ?? '',
        topicId: data?.attempt?.topic_id ?? null,
        subtopicId: data?.attempt?.subtopic_id ?? null,
        questions: asList(data?.questions).map(normalizeQuestion),
        scopeUsed: data?.scope_used ?? null,
        scopeNote: data?.scope_note ?? null,
      }
    } catch (error) {
      sessionError.value = firstApiError(error, 'Unable to start a Review Bot session right now.')
    } finally {
      sessionStarting.value = false
    }
  }

  function answerQuestion(questionId, optionId) {
    if (result.value) return
    answers[questionId] = optionId
  }

  async function submitSession() {
    if (!hasSession.value || !allAnswered.value) {
      sessionError.value = 'Answer every question before submitting.'
      return
    }

    submitting.value = true
    sessionError.value = ''

    try {
      const payload = {
        answers: session.value.questions.map((question) => ({
          question_id: question.questionId,
          option_id: answers[question.questionId] ?? null,
        })),
      }

      const { data } = await reviewBotApi.submitSession(session.value.attemptId, payload)

      result.value = {
        scorePercent: Number(data?.score_percent ?? 0),
        correctAnswers: Number(data?.correct_answers ?? 0),
        totalItems: Number(data?.total_items ?? 0),
        subject: data?.subject ?? '',
        topic: data?.topic ?? null,
        subtopic: data?.subtopic ?? null,
        missedItems: asList(data?.missed_items),
        improved: data?.improved ?? null,
        previousScorePercent: data?.previous_score_percent ?? null,
        recommendation: data?.recommendation ?? null,
      }
    } catch (error) {
      sessionError.value = firstApiError(error, 'Unable to submit this review session.')
    } finally {
      submitting.value = false
    }
  }

  function startAnother() {
    resetSession()
  }

  function reviewNextTopic() {
    const next = result.value?.recommendation?.next_focus
    if (!next) return

    resetSession()
    setMode('manual')
    selectSubject(next.subject ?? '')
    if (next.topic_id) selectTopic(Number(next.topic_id))
    if (next.subtopic_id) selectSubtopic(Number(next.subtopic_id))
  }

  function repeatSameTopic() {
    resetSession()
    startSession()
  }

  onMounted(async () => {
    await loadCatalog()

    // Deep link from the exam result page's "Review Your Weak Areas"
    // action — preselect that subject/topic/subtopic instead of leaving
    // the picker empty.
    const requestedSubject = String(route.query?.subject ?? '').trim()
    const requestedTopicId = Number(route.query?.topic_id)
    const requestedSubtopicId = Number(route.query?.subtopic_id)

    if (requestedSubject && subjects.value.some((entry) => entry.subject === requestedSubject)) {
      setMode('manual')
      selectSubject(requestedSubject)

      if (Number.isFinite(requestedTopicId) && requestedTopicId > 0) {
        selectTopic(requestedTopicId)
      }

      if (Number.isFinite(requestedSubtopicId) && requestedSubtopicId > 0) {
        selectSubtopic(requestedSubtopicId)
      }
    }
  })

  return {
    catalogLoading,
    catalogError,
    subjects,
    readySubjects,
    weakArea,
    weakAreaLabel,
    weakAreaScoreText,
    mode,
    form,
    questionCountOptions,
    selectedSubjectEntry,
    availableTopics,
    selectedTopicEntry,
    availableSubtopics,
    availableDifficulties,
    canStart,
    sessionStarting,
    sessionError,
    session,
    hasSession,
    answers,
    answeredCount,
    allAnswered,
    submitting,
    result,
    selectSubject,
    selectTopic,
    selectSubtopic,
    setDifficulty,
    setQuestionCount,
    setMode,
    startSession,
    answerQuestion,
    submitSession,
    startAnother,
    reviewNextTopic,
    repeatSameTopic,
    loadCatalog,
  }
}
