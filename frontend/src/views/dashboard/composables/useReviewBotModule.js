import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { reviewBotApi } from '@/api'

function firstApiError(error, fallbackMessage) {
  return error?.response?.data?.message ?? fallbackMessage
}

function normalizeQuestionRecord(question) {
  if (!question || typeof question !== 'object') return null

  const rawOptions = Array.isArray(question.options) ? question.options : []
  const options = rawOptions
    .map((option) => {
      if (!option || typeof option !== 'object') return null

      const id = Number(option.id ?? 0)
      const text = String(option.text ?? '').trim()

      if (!id || !text) return null

      return {
        id,
        label: String(option.label ?? '').trim(),
        text,
        is_correct: typeof option.is_correct === 'boolean' ? option.is_correct : null,
      }
    })
    .filter(Boolean)

  if (!question.question_id || !String(question.question_text ?? '').trim() || options.length < 2) {
    return null
  }

  return {
    id: Number(question.question_id),
    itemNumber: Number(question.item_number ?? 0),
    prompt: String(question.question_text ?? '').trim(),
    type: String(question.question_type ?? 'multiple_choice'),
    subject: String(question.subject ?? '').trim() || 'General',
    topic: String(question.topic ?? '').trim() || '',
    options,
    answer: {
      selected_option_id: Number(question.answer?.selected_option_id ?? 0) || null,
      answer_text: question.answer?.answer_text ?? '',
      is_correct: typeof question.answer?.is_correct === 'boolean' ? question.answer.is_correct : null,
    },
    correctAnswer: question.correct_answer ?? null,
  }
}

function formatWeakAreaLabel(weakArea) {
  if (!weakArea || typeof weakArea !== 'object') return 'No weak area available yet'
  if (String(weakArea.basis ?? '') === 'topic' && String(weakArea.topic ?? '').trim()) {
    return `${weakArea.subject} • ${weakArea.topic}`
  }

  return String(weakArea.subject ?? '').trim() || 'No weak area available yet'
}

export function useReviewBotModule() {
  const route = useRoute()
  const router = useRouter()
  const overviewLoading = ref(false)
  const quizLoading = ref(false)
  const reviewError = ref('')
  const reviewMessage = ref('')
  const generatedQuestions = ref([])
  const currentAttempt = ref(null)
  const practiceOverview = ref({
    weak_area: null,
    available_questions: 0,
  })
  const answers = reactive({})

  const form = reactive({
    questionCount: 10,
  })
  const autoStartHandled = ref(false)

  const questionCountOptions = [5, 10, 15, 20]

  const hasQuiz = computed(() => generatedQuestions.value.length > 0)
  const weakArea = computed(() => practiceOverview.value?.weak_area ?? null)
  const availableQuestions = computed(() => Number(practiceOverview.value?.available_questions ?? 0))
  const weakAreaLabel = computed(() => formatWeakAreaLabel(weakArea.value))
  const weakAreaBasisLabel = computed(() => (
    !weakArea.value
      ? 'Awaiting diagnostics'
      : (String(weakArea.value?.basis ?? '') === 'topic' ? 'Topic weakness' : 'Subject weakness')
  ))
  const canGenerate = computed(() => (
    Number(form.questionCount) >= 3
    && weakArea.value
    && availableQuestions.value > 0
    && !quizLoading.value
  ))
  const attemptSubmitted = computed(() => String(currentAttempt.value?.status ?? '') === 'submitted')
  const answeredCount = computed(() => Number(currentAttempt.value?.answered_count ?? 0))
  const allAnswered = computed(() => (
    hasQuiz.value && answeredCount.value === generatedQuestions.value.length
  ))
  const correctCount = computed(() => Number(currentAttempt.value?.correct_answers ?? 0))
  const scorePercent = computed(() => Number(currentAttempt.value?.score_percent ?? 0))

  function questionHasAnswer(question) {
    if (!question || typeof question !== 'object') return false

    const hasSelectedOption = Number(question.answer?.selected_option_id ?? 0) > 0
    const hasTextAnswer = String(question.answer?.answer_text ?? '').trim().length > 0

    return hasSelectedOption || hasTextAnswer
  }

  function questionFeedbackVisible(question) {
    return attemptSubmitted.value || questionHasAnswer(question)
  }

  function clearAnswers() {
    Object.keys(answers).forEach((key) => {
      delete answers[key]
    })
  }

  function hydrateAttemptPayload(data) {
    currentAttempt.value = data?.attempt ?? null
    generatedQuestions.value = Array.isArray(data?.questions)
      ? data.questions.map(normalizeQuestionRecord).filter(Boolean)
      : []

    clearAnswers()

    generatedQuestions.value.forEach((question) => {
      answers[question.id] = question.answer?.selected_option_id ?? null
    })
  }

  function resetQuizState() {
    currentAttempt.value = null
    generatedQuestions.value = []
    clearAnswers()
  }

  function setQuestionCount(count) {
    form.questionCount = Number(count)
  }

  async function loadOverview() {
    overviewLoading.value = true
    reviewError.value = ''

    try {
      const { data } = await reviewBotApi.overview()
      practiceOverview.value = {
        weak_area: data?.weak_area ?? null,
        available_questions: Number(data?.available_questions ?? 0),
      }
    } catch (error) {
      reviewError.value = firstApiError(error, 'Unable to load weak-area practice right now.')
    } finally {
      overviewLoading.value = false
    }
  }

  function autoStartRequested() {
    return String(route.query?.auto_start ?? '') === '1'
  }

  async function clearAutoStartQuery() {
    if (!autoStartRequested()) return

    const nextQuery = {
      ...route.query,
    }

    delete nextQuery.auto_start
    delete nextQuery.launch

    await router.replace({
      query: nextQuery,
    })
  }

  async function maybeAutoStartRecommendedPractice() {
    if (!autoStartRequested() || autoStartHandled.value) {
      return
    }

    autoStartHandled.value = true

    if (canGenerate.value) {
      await generateQuiz()
      await clearAutoStartQuery()
      return
    }

    if (!weakArea.value) {
      reviewError.value = 'Recommended practice is not ready yet because no weak area has been detected from your submitted exams.'
    } else if (availableQuestions.value <= 0) {
      reviewError.value = 'Recommended practice is not ready yet because no approved remedial questions are available for your current weak area.'
    }

    await clearAutoStartQuery()
  }

  watch(
    () => String(route.query?.auto_start ?? ''),
    (value) => {
      if (value === '1') {
        autoStartHandled.value = false
        void maybeAutoStartRecommendedPractice()
      }
    },
  )

  watch(
    [weakArea, availableQuestions, overviewLoading],
    ([nextWeakArea, nextAvailableQuestions, nextOverviewLoading]) => {
      if (
        autoStartRequested()
        && !autoStartHandled.value
        && !nextOverviewLoading
        && nextWeakArea
        && Number(nextAvailableQuestions) > 0
      ) {
        void maybeAutoStartRecommendedPractice()
      }
    },
  )

  async function generateQuiz() {
    if (!canGenerate.value) {
      reviewError.value = weakArea.value
        ? 'No approved remedial questions are ready for this weak area yet.'
        : 'No weak-area recommendation is available yet.'
      return
    }

    quizLoading.value = true
    reviewError.value = ''
    reviewMessage.value = ''

    try {
      const { data } = await reviewBotApi.startAttempt({
        question_count: Number(form.questionCount),
      })

      hydrateAttemptPayload(data)
      reviewMessage.value = String(data?.message ?? 'Practice attempt started.')
    } catch (error) {
      resetQuizState()
      reviewError.value = firstApiError(error, 'Unable to start weak-area practice right now.')
    } finally {
      quizLoading.value = false
    }
  }

  async function answerQuestion(questionId, optionId) {
    if (attemptSubmitted.value || !currentAttempt.value?.id) return

    answers[questionId] = optionId
    reviewError.value = ''

    try {
      const { data } = await reviewBotApi.saveAnswer(currentAttempt.value.id, {
        question_id: Number(questionId),
        selected_option_id: Number(optionId),
      })
      hydrateAttemptPayload(data)
    } catch (error) {
      reviewError.value = firstApiError(error, 'Unable to save this practice answer.')
    }
  }

  async function submitQuiz() {
    reviewError.value = ''
    reviewMessage.value = ''

    if (!currentAttempt.value?.id) {
      reviewError.value = 'Start a practice attempt first.'
      return
    }

    if (!allAnswered.value) {
      reviewError.value = 'Answer every question before submitting your practice attempt.'
      return
    }

    quizLoading.value = true

    try {
      const { data } = await reviewBotApi.submitAttempt(currentAttempt.value.id)
      hydrateAttemptPayload(data)
      reviewMessage.value = String(data?.message ?? 'Practice attempt submitted.')
      await loadOverview()
    } catch (error) {
      reviewError.value = firstApiError(error, 'Unable to submit your practice attempt right now.')
    } finally {
      quizLoading.value = false
    }
  }

  onMounted(() => {
    void (async () => {
      await loadOverview()
      await maybeAutoStartRecommendedPractice()
    })()
  })

  return {
    availableQuestions,
    answeredCount,
    allAnswered,
    answerQuestion,
    answers,
    attemptSubmitted,
    canGenerate,
    correctCount,
    form,
    generateQuiz,
    generatedQuestions,
    hasQuiz,
    loadOverview,
    overviewLoading,
    questionCountOptions,
    questionFeedbackVisible,
    questionHasAnswer,
    quizLoading,
    resetQuizState,
    reviewError,
    reviewMessage,
    scorePercent,
    setQuestionCount,
    submitQuiz,
    weakArea,
    weakAreaBasisLabel,
    weakAreaLabel,
  }
}
