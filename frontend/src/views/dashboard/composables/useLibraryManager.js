import { computed, reactive, ref } from 'vue'
import { questionsApi } from '@/api/questions.api'
import { LIBRARY_SUBJECT_CATEGORIES } from '@/constants/librarySubjects'

const REMEDIAL_ITEM_STYLES = [
  'reverse_association',
  'definition_to_term',
  'scenario_based',
  'comparison',
]

const REMEDIAL_DIFFICULTIES = ['easy', 'medium', 'hard']

function asBooleanValue(source) {
  if (typeof source === 'boolean') return source
  if (source && typeof source === 'object' && 'value' in source) {
    return Boolean(source.value)
  }

  return false
}

export function useLibraryManager({
  canManageLibraries,
  parseApiError,
  onBanksChanged,
} = {}) {
  const showLibraryQuestionModal = ref(false)
  const showLibraryBankQuestionsModal = ref(false)
  const showDeleteLibraryBankModal = ref(false)
  const showRemedialEditModal = ref(false)

  const libraryLoading = ref(false)
  const libraryReviewLoading = ref(false)
  const libraryBankQuestionsLoading = ref(false)
  const librarySaving = ref(false)
  const libraryDeleting = ref(false)
  const libraryReviewSaving = ref(false)
  const libraryGenerationSaving = ref(false)
  const libraryRemedialEditSaving = ref(false)
  const libraryError = ref('')
  const libraryMessage = ref('')

  const libraryParsing = ref(false)
  const libraryParseError = ref('')
  const libraryDocxName = ref('')
  const libraryFileInputKey = ref(0)

  const libraryQuestionBanks = ref([])
  const selectedLibraryBank = ref(null)
  const selectedLibraryQuestionBank = ref(null)
  const selectedRemedialQuestion = ref(null)
  const libraryBankReviewMode = ref('assessment')
  const libraryPreviewWarnings = ref([])
  const digitalizedQuestions = ref([])
  const libraryBankQuestions = ref([])
  const libraryBankRemedialQuestions = ref([])
  const libraryBankQuestionViewTab = ref('assessment')
  const libraryBankRemedialStatusFilter = ref('pending_review')
  const remedialQueue = ref([])

  const libraryBankRemedialStatusSummary = computed(() => ({
    ...buildRemedialStatusSummary(libraryBankRemedialQuestions.value),
  }))

  const filteredLibraryBankRemedialQuestions = computed(() => {
    const filter = String(libraryBankRemedialStatusFilter.value ?? 'pending_review')

    if (filter === 'all') {
      return libraryBankRemedialQuestions.value
    }

    return libraryBankRemedialQuestions.value.filter((question) => (
      String(question?.status ?? '') === filter
    ))
  })

  const libraryBankRemedialGroups = computed(() => (
    buildRemedialSourceGroups(filteredLibraryBankRemedialQuestions.value)
  ))

  const remedialQueueGroups = computed(() => {
    const bankGroups = new Map()

    remedialQueue.value.forEach((question) => {
      const bank = question?.question_bank ?? question?.questionBank ?? null
      const bankId = Number(bank?.id ?? question?.question_bank_id ?? 0)
      const mapKey = bankId > 0 ? bankId : `bank-${question?.id ?? 0}`

      if (!bankGroups.has(mapKey)) {
        bankGroups.set(mapKey, {
          bank: normalizeQuestionBank(bank, question),
          questions: [],
        })
      }

      bankGroups.get(mapKey).questions.push(question)
    })

    return Array.from(bankGroups.values())
      .map((group) => ({
        ...group,
        statusSummary: buildRemedialStatusSummary(group.questions),
        sourceGroups: buildRemedialSourceGroups(group.questions),
      }))
      .sort((left, right) => {
        const pendingDifference = (right?.statusSummary?.pending_review ?? 0) - (left?.statusSummary?.pending_review ?? 0)

        if (pendingDifference !== 0) {
          return pendingDifference
        }

        return String(left?.bank?.title ?? '').localeCompare(
          String(right?.bank?.title ?? ''),
          undefined,
          { sensitivity: 'base' },
        )
      })
  })

  const groupedLibraryPreviewWarnings = computed(() => {
    if (libraryPreviewWarnings.value.length === 0) return []

    const warnings = libraryPreviewWarnings.value
    const grouped = []

    const parseWarning = (text) => {
      const match = text.match(/^Question (\d+) (has no detected correct answer\.|has fewer than 2 options\.|does not match any option label\.)(.*?)$/)
      if (match) {
        return { isGroupable: true, number: parseInt(match[1], 10), suffix: match[2] + match[3] }
      }
      return { isGroupable: false, text }
    }

    let currentGroup = null

    for (const warning of warnings) {
      const parsed = parseWarning(warning)

      if (!parsed.isGroupable) {
        if (currentGroup) {
          grouped.push(formatGroup(currentGroup, digitalizedQuestions.value.length))
          currentGroup = null
        }
        grouped.push(warning)
        continue
      }

      if (!currentGroup) {
        currentGroup = { numbers: [parsed.number], suffix: parsed.suffix }
      } else if (currentGroup.suffix === parsed.suffix) {
        currentGroup.numbers.push(parsed.number)
      } else {
        grouped.push(formatGroup(currentGroup, digitalizedQuestions.value.length))
        currentGroup = { numbers: [parsed.number], suffix: parsed.suffix }
      }
    }

    if (currentGroup) {
      grouped.push(formatGroup(currentGroup, digitalizedQuestions.value.length))
    }

    return grouped
  })

  function formatGroup(group, totalQuestionsLength) {
    if (group.numbers.length === 1) {
      return `Question ${group.numbers[0]} ${group.suffix}`
    }

    const nums = group.numbers.sort((a, b) => a - b)
    const totalAffected = nums.length

    // If 0 valid items at all
    if (totalQuestionsLength > 0 && totalAffected === totalQuestionsLength) {
      return `All ${totalQuestionsLength} questions ${group.suffix.replace(/^has /, 'have ')}`
    }

    // Dynamic inversion threshold: only flip the message if the number of VALID items
    // is ≤ 20% of the total (minimum safety floor of 3).
    // This ensures the inverted form only appears when it produces a noticeably shorter message.
    // e.g.  20 items → threshold = 4   (≤4 valid → invert)
    //       50 items → threshold = 10  (≤10 valid → invert)
    //      100 items → threshold = 20  (≤20 valid → invert)
    const invertThreshold = Math.max(3, Math.round(totalQuestionsLength * 0.2))

    if (totalQuestionsLength > 0 && totalAffected > (totalQuestionsLength / 2)) {
      const allItemNumbers = Array.from({ length: totalQuestionsLength }, (_, i) => i + 1)
      const validItems = allItemNumbers.filter(n => !nums.includes(n))

      if (validItems.length <= invertThreshold) {
        let inverseSubject = 'answers'
        if (group.suffix.includes('options')) inverseSubject = 'options'

        return `Only Question${validItems.length > 1 ? 's' : ''} ${validItems.join(', ')} have valid ${inverseSubject}. (All others ${group.suffix.replace(/^has /, 'have ')})`
      }
    }

    const ranges = buildRanges(nums)
    return `Question${ranges.length > 1 || nums.length > 1 ? 's' : ''} ${ranges.join(', ')} ${group.suffix.replace(/^has /, 'have ')}`
  }

  function buildRanges(nums) {
    if (nums.length === 0) return []
    const ranges = []
    let rangeStart = nums[0]
    let rangeEnd = nums[0]

    for (let i = 1; i < nums.length; i++) {
      if (nums[i] === rangeEnd + 1) {
        rangeEnd = nums[i]
      } else {
        ranges.push(rangeStart === rangeEnd ? `${rangeStart}` : `${rangeStart}-${rangeEnd}`)
        rangeStart = nums[i]
        rangeEnd = nums[i]
      }
    }
    ranges.push(rangeStart === rangeEnd ? `${rangeStart}` : `${rangeStart}-${rangeEnd}`)
    return ranges
  }

  function buildRemedialStatusSummary(questions = []) {
    return {
      all: questions.length,
      pending_review: questions.filter((question) => String(question?.status ?? '') === 'pending_review').length,
      approved: questions.filter((question) => String(question?.status ?? '') === 'approved').length,
      rejected: questions.filter((question) => String(question?.status ?? '') === 'rejected').length,
    }
  }

  function normalizeSourceQuestion(sourceQuestion) {
    if (sourceQuestion) {
      return {
        ...sourceQuestion,
        item_number: Number(sourceQuestion.item_number ?? 0),
        question_text: String(sourceQuestion.question_text ?? '').trim(),
      }
    }

    return {
      id: null,
      item_number: 0,
      question_text: 'No source question preview available.',
    }
  }

  function remedialQuestionSort(left, right) {
    const statusOrder = {
      pending_review: 0,
      approved: 1,
      rejected: 2,
    }

    const leftStatus = statusOrder[String(left?.status ?? 'pending_review')] ?? 99
    const rightStatus = statusOrder[String(right?.status ?? 'pending_review')] ?? 99

    if (leftStatus !== rightStatus) {
      return leftStatus - rightStatus
    }

    return Number(left?.item_number ?? 0) - Number(right?.item_number ?? 0)
  }

  function buildRemedialSourceGroups(questions = []) {
    const groups = new Map()

    questions.forEach((question) => {
      const sourceQuestion = question?.source_question ?? question?.sourceQuestion ?? null
      const sourceId = Number(sourceQuestion?.id ?? question?.source_question_id ?? 0)
      const mapKey = sourceId > 0 ? sourceId : `orphan-${question?.id ?? 0}`

      if (!groups.has(mapKey)) {
        groups.set(mapKey, {
          source_question: normalizeSourceQuestion(sourceQuestion),
          variants: [],
        })
      }

      groups.get(mapKey).variants.push(question)
    })

    return Array.from(groups.values())
      .map((group) => ({
        ...group,
        statusSummary: buildRemedialStatusSummary(group.variants),
        variants: [...group.variants].sort(remedialQuestionSort),
      }))
      .sort((left, right) => {
        const leftItem = Number(left?.source_question?.item_number ?? 0)
        const rightItem = Number(right?.source_question?.item_number ?? 0)

        if (leftItem !== rightItem) {
          return leftItem - rightItem
        }

        return String(left?.source_question?.question_text ?? '').localeCompare(
          String(right?.source_question?.question_text ?? ''),
          undefined,
          { sensitivity: 'base' },
        )
      })
  }

  function normalizeQuestionBank(bank, question) {
    return {
      id: Number(bank?.id ?? question?.question_bank_id ?? 0) || null,
      title: String(bank?.title ?? 'Untitled Question Set').trim() || 'Untitled Question Set',
      subject: String(bank?.subject ?? question?.subject ?? '').trim(),
      remedial_generation_status: String(bank?.remedial_generation_status ?? 'completed').trim() || 'completed',
    }
  }

  function patchBankCounts(bankId, applyPatch) {
    const normalizedBankId = Number(bankId)

    if (!normalizedBankId) return

    libraryQuestionBanks.value = libraryQuestionBanks.value.map((bank) => {
      if (Number(bank?.id ?? 0) !== normalizedBankId) {
        return bank
      }

      return applyPatch({ ...bank })
    })

    if (Number(selectedLibraryQuestionBank.value?.id ?? 0) === normalizedBankId) {
      selectedLibraryQuestionBank.value = applyPatch({ ...selectedLibraryQuestionBank.value })
    }
  }

  function queueQuestionBankId(question) {
    return Number(
      question?.question_bank?.id
      ?? question?.questionBank?.id
      ?? question?.question_bank_id
      ?? 0,
    )
  }

  function patchQueueQuestion(nextQuestion) {
    const questionId = Number(nextQuestion?.id ?? 0)

    if (!questionId) return

    const nextStatus = String(nextQuestion?.status ?? '')

    if (nextStatus === 'rejected') {
      remedialQueue.value = remedialQueue.value.filter((question) => Number(question?.id ?? 0) !== questionId)
      return
    }

    let matched = false

    remedialQueue.value = remedialQueue.value.map((question) => {
      if (Number(question?.id ?? 0) !== questionId) {
        return question
      }

      matched = true
      return {
        ...question,
        ...nextQuestion,
      }
    })

    if (!matched) {
      remedialQueue.value = [nextQuestion, ...remedialQueue.value]
    }
  }

  function patchRemedialQuestionLocally(nextQuestion, previousStatus = null) {
    const nextStatus = String(nextQuestion?.status ?? '')
    const bankId = queueQuestionBankId(nextQuestion)

    patchQueueQuestion(nextQuestion)

    if (Number(selectedRemedialQuestion.value?.id ?? 0) === Number(nextQuestion?.id ?? 0)) {
      selectedRemedialQuestion.value = {
        ...selectedRemedialQuestion.value,
        ...nextQuestion,
      }
    }

    patchBankCounts(bankId, (bank) => {
      const pendingCount = Number(bank?.remedial_pending_count ?? 0)
      const approvedCount = Number(bank?.remedial_approved_count ?? 0)
      const rejectedCount = Number(bank?.remedial_rejected_count ?? 0)

      const nextBank = { ...bank }

      if (previousStatus === 'pending_review') {
        nextBank.remedial_pending_count = Math.max(0, pendingCount - 1)
      } else if (previousStatus === 'approved') {
        nextBank.remedial_approved_count = Math.max(0, approvedCount - 1)
      } else if (previousStatus === 'rejected') {
        nextBank.remedial_rejected_count = Math.max(0, rejectedCount - 1)
      }

      if (nextStatus === 'pending_review') {
        nextBank.remedial_pending_count = Number(nextBank.remedial_pending_count ?? pendingCount) + 1
      } else if (nextStatus === 'approved') {
        nextBank.remedial_approved_count = Number(nextBank.remedial_approved_count ?? approvedCount) + 1
      } else if (nextStatus === 'rejected') {
        nextBank.remedial_rejected_count = Number(nextBank.remedial_rejected_count ?? rejectedCount) + 1
      }

      return nextBank
    })
  }

  function patchRemedialSetLocally(bankId, targetStatus, currentStatus) {
    const normalizedBankId = Number(bankId)
    let affectedCount = 0

    remedialQueue.value = remedialQueue.value.flatMap((question) => {
      if (
        queueQuestionBankId(question) !== normalizedBankId
        || String(question?.status ?? '') !== String(currentStatus)
      ) {
        return [question]
      }

      affectedCount += 1

      if (String(targetStatus) === 'rejected') {
        return []
      }

      return [{
        ...question,
        status: targetStatus,
      }]
    })

    if (affectedCount < 1) return

    patchBankCounts(normalizedBankId, (bank) => {
      const nextBank = { ...bank }

      if (String(currentStatus) === 'pending_review') {
        nextBank.remedial_pending_count = Math.max(0, Number(bank?.remedial_pending_count ?? 0) - affectedCount)
      } else if (String(currentStatus) === 'approved') {
        nextBank.remedial_approved_count = Math.max(0, Number(bank?.remedial_approved_count ?? 0) - affectedCount)
      } else if (String(currentStatus) === 'rejected') {
        nextBank.remedial_rejected_count = Math.max(0, Number(bank?.remedial_rejected_count ?? 0) - affectedCount)
      }

      if (String(targetStatus) === 'pending_review') {
        nextBank.remedial_pending_count = Number(nextBank.remedial_pending_count ?? 0) + affectedCount
      } else if (String(targetStatus) === 'approved') {
        nextBank.remedial_approved_count = Number(nextBank.remedial_approved_count ?? 0) + affectedCount
      } else if (String(targetStatus) === 'rejected') {
        nextBank.remedial_rejected_count = Number(nextBank.remedial_rejected_count ?? 0) + affectedCount
      }

      return nextBank
    })
  }

  function refreshLibraryViewsInBackground() {
    void Promise.all([
      loadLibraryBanks(),
      loadRemedialQueue(),
      showLibraryBankQuestionsModal.value
        ? loadLibraryBankQuestions(selectedLibraryQuestionBank.value, { preserveViewState: true })
        : Promise.resolve(),
    ])
  }

  const libraryForm = reactive({
    questionName: '',
    subjectCategory: '',
    file: null,
  })

  const remedialEditForm = reactive({
    questionText: '',
    itemStyle: 'reverse_association',
    difficulty: 'medium',
    cognitiveLevel: '',
    testedConcept: '',
    differenceFromSource: '',
    correctAnswer: 'A',
    options: {
      A: '',
      B: '',
      C: '',
      D: '',
    },
  })

  const librarySubjectCategories = LIBRARY_SUBJECT_CATEGORIES
  const remedialItemStyleOptions = REMEDIAL_ITEM_STYLES
  const remedialDifficultyOptions = REMEDIAL_DIFFICULTIES

  const canSaveLibraryQuestionBank = computed(() => (
    !librarySaving.value
    && !libraryParsing.value
    && libraryForm.questionName.trim().length > 0
    && libraryForm.subjectCategory.trim().length > 0
    && digitalizedQuestions.value.length > 0
  ))

  const canSaveRemedialEdit = computed(() => (
    !libraryRemedialEditSaving.value
    && selectedRemedialQuestion.value?.id
    && remedialEditForm.questionText.trim().length > 0
    && REMEDIAL_ITEM_STYLES.includes(remedialEditForm.itemStyle)
    && REMEDIAL_DIFFICULTIES.includes(remedialEditForm.difficulty)
    && ['A', 'B', 'C', 'D'].includes(remedialEditForm.correctAnswer)
    && ['A', 'B', 'C', 'D'].every((label) => String(remedialEditForm.options[label] ?? '').trim().length > 0)
  ))

  function resolveApiError(error, fallbackMessage) {
    if (typeof parseApiError === 'function') {
      return parseApiError(error, fallbackMessage)
    }

    return fallbackMessage
  }

  async function notifyBanksChanged() {
    if (typeof onBanksChanged !== 'function') return
    await onBanksChanged()
  }

  function resetLibraryQuestionState() {
    libraryForm.questionName = ''
    libraryForm.subjectCategory = ''
    libraryForm.file = null
    libraryDocxName.value = ''
    libraryParseError.value = ''
    libraryParsing.value = false
    libraryPreviewWarnings.value = []
    digitalizedQuestions.value = []
    libraryFileInputKey.value += 1
  }

  function resetRemedialEditForm() {
    remedialEditForm.questionText = ''
    remedialEditForm.itemStyle = 'reverse_association'
    remedialEditForm.difficulty = 'medium'
    remedialEditForm.cognitiveLevel = ''
    remedialEditForm.testedConcept = ''
    remedialEditForm.differenceFromSource = ''
    remedialEditForm.correctAnswer = 'A'
    remedialEditForm.options.A = ''
    remedialEditForm.options.B = ''
    remedialEditForm.options.C = ''
    remedialEditForm.options.D = ''
  }

  function remedialOptionMapFromQuestion(question) {
    const options = Array.isArray(question?.options) ? question.options : []
    const optionMap = { A: '', B: '', C: '', D: '' }

    options.forEach((option) => {
      const label = String(option?.option_label ?? '').trim().toUpperCase()
      if (label && label in optionMap) {
        optionMap[label] = String(option?.option_text ?? '')
      }
    })

    return optionMap
  }

  function openRemedialEditModal(question) {
    if (!question?.id) return

    selectedRemedialQuestion.value = question
    remedialEditForm.questionText = String(question.question_text ?? '')
    remedialEditForm.itemStyle = REMEDIAL_ITEM_STYLES.includes(String(question.item_style ?? ''))
      ? String(question.item_style)
      : 'reverse_association'
    remedialEditForm.difficulty = REMEDIAL_DIFFICULTIES.includes(String(question.difficulty ?? ''))
      ? String(question.difficulty)
      : 'medium'
    remedialEditForm.cognitiveLevel = String(question.cognitive_level ?? '')
    remedialEditForm.testedConcept = String(question.tested_concept ?? '')
    remedialEditForm.differenceFromSource = String(question.difference_from_source ?? '')
    remedialEditForm.correctAnswer = ['A', 'B', 'C', 'D'].includes(String(question.answer_label ?? '').trim().toUpperCase())
      ? String(question.answer_label).trim().toUpperCase()
      : 'A'

    const optionMap = remedialOptionMapFromQuestion(question)
    remedialEditForm.options.A = optionMap.A
    remedialEditForm.options.B = optionMap.B
    remedialEditForm.options.C = optionMap.C
    remedialEditForm.options.D = optionMap.D

    showRemedialEditModal.value = true
  }

  function closeRemedialEditModal() {
    showRemedialEditModal.value = false
    selectedRemedialQuestion.value = null
    resetRemedialEditForm()
  }

  function openLibraryQuestionModal() {
    resetLibraryQuestionState()
    showLibraryQuestionModal.value = true
  }

  function closeLibraryQuestionModal() {
    showLibraryQuestionModal.value = false
    resetLibraryQuestionState()
  }

  function resetLibraryBankQuestionState() {
    selectedLibraryQuestionBank.value = null
    libraryBankQuestions.value = []
    libraryBankRemedialQuestions.value = []
    libraryBankQuestionViewTab.value = 'assessment'
    libraryBankReviewMode.value = 'assessment'
    libraryBankRemedialStatusFilter.value = 'pending_review'
    libraryBankQuestionsLoading.value = false
  }

  async function loadLibraryBankQuestions(bank, { preserveViewState = false } = {}) {
    const targetBank = bank ?? selectedLibraryQuestionBank.value
    const bankId = Number(targetBank?.id ?? 0)

    if (!bankId) return

    libraryBankQuestionsLoading.value = true
    libraryError.value = ''

    try {
      const { data } = await questionsApi.listBankQuestions(bankId)
      const resolvedBank = data?.bank ?? targetBank
      selectedLibraryQuestionBank.value = resolvedBank
      libraryBankQuestions.value = Array.isArray(data?.assessment_questions)
        ? data.assessment_questions
        : (Array.isArray(data?.questions) ? data.questions : [])
      libraryBankRemedialQuestions.value = Array.isArray(data?.remedial_questions)
        ? data.remedial_questions
        : []
      const remedialSummary = buildRemedialStatusSummary(libraryBankRemedialQuestions.value)
      const hasPendingRemedialQuestions = remedialSummary.pending_review > 0

      if (!preserveViewState) {
        libraryBankRemedialStatusFilter.value = hasPendingRemedialQuestions ? 'pending_review' : 'all'
        libraryBankQuestionViewTab.value = hasPendingRemedialQuestions
          ? 'remedial'
          : 'assessment'
      } else if (
        libraryBankQuestionViewTab.value === 'remedial'
        && libraryBankRemedialQuestions.value.length === 0
      ) {
        libraryBankQuestionViewTab.value = 'assessment'
      } else if (
        libraryBankQuestionViewTab.value === 'remedial'
        && libraryBankRemedialStatusFilter.value !== 'all'
        && remedialSummary[libraryBankRemedialStatusFilter.value] === 0
      ) {
        libraryBankRemedialStatusFilter.value = hasPendingRemedialQuestions ? 'pending_review' : 'all'
      }
    } finally {
      libraryBankQuestionsLoading.value = false
    }
  }

  async function openLibraryBankQuestionsModal(bank, {
    initialTab = 'assessment',
    reviewMode = 'assessment',
  } = {}) {
    if (!bank?.id) return

    showLibraryBankQuestionsModal.value = true
    selectedLibraryQuestionBank.value = bank
    libraryBankQuestions.value = []
    libraryBankRemedialQuestions.value = []
    libraryBankReviewMode.value = reviewMode === 'remedial' ? 'remedial' : 'assessment'
    libraryBankQuestionViewTab.value = initialTab === 'remedial' ? 'remedial' : 'assessment'

    try {
      await loadLibraryBankQuestions(bank, { preserveViewState: true })
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to load the questions for this bank right now.')
      closeLibraryBankQuestionsModal()
    }
  }

  function closeLibraryBankQuestionsModal() {
    showLibraryBankQuestionsModal.value = false
    resetLibraryBankQuestionState()
  }

  function setLibraryBankQuestionViewTab(tab) {
    const normalized = String(tab ?? '').toLowerCase()
    libraryBankQuestionViewTab.value = normalized === 'remedial' ? 'remedial' : 'assessment'
  }

  function setLibraryBankRemedialStatusFilter(filter) {
    const normalized = String(filter ?? 'pending_review').toLowerCase()

    if (!['all', 'pending_review', 'approved', 'rejected'].includes(normalized)) {
      libraryBankRemedialStatusFilter.value = 'pending_review'
      return
    }

    libraryBankRemedialStatusFilter.value = normalized
  }

  function openDeleteLibraryBankModal(bank) {
    if (!bank?.id) return

    selectedLibraryBank.value = bank
    showDeleteLibraryBankModal.value = true
  }

  function closeDeleteLibraryBankModal() {
    showDeleteLibraryBankModal.value = false
    selectedLibraryBank.value = null
  }

  async function handleLibraryDocxChange(event) {
    const file = event.target?.files?.[0] ?? null

    libraryParseError.value = ''
    libraryPreviewWarnings.value = []
    digitalizedQuestions.value = []
    libraryForm.file = null
    libraryDocxName.value = ''

    if (!file) return

    const normalizedFileName = file.name.toLowerCase()
    const isLegacyDoc = normalizedFileName.endsWith('.doc') && !normalizedFileName.endsWith('.docx')
    const isDocx = normalizedFileName.endsWith('.docx')
    if (!isDocx) {
      libraryParseError.value = isLegacyDoc
        ? 'Legacy .doc files are not supported. Open the file in Word or Google Docs and save it as .docx, then try again.'
        : 'Please upload a valid .docx file.'
      libraryFileInputKey.value += 1
      return
    }

    libraryForm.file = file
    libraryDocxName.value = file.name
    libraryParsing.value = true

    try {
      const formData = new FormData()
      formData.append('file', file)

      const { data } = await questionsApi.importPreview(formData)

      const preview = data.preview ?? {}
      const previewQuestions = preview.questions ?? []

      if (previewQuestions.length === 0) {
        libraryParseError.value = 'No valid question pattern was detected in this DOCX file.'
        return
      }

      digitalizedQuestions.value = previewQuestions.map((question, index) => {
        const options = Array.isArray(question.options) ? question.options : []

        return {
          id: Number(question.item_number ?? (index + 1)),
          item_number: Number(question.item_number ?? (index + 1)),
          text: String(question.text ?? ''),
          question_type: String(question.question_type ?? (options.length > 0 ? 'multiple_choice' : 'open_ended')),
          options: options.map((option, optionIndex) => ({
            label: String(option.label ?? String.fromCharCode(65 + optionIndex)),
            text: String(option.text ?? ''),
            is_correct: Boolean(option.is_correct),
          })),
          answer_label: question.answer_label ? String(question.answer_label) : '',
          answer_text: question.answer_text ? String(question.answer_text) : '',
          topic_name: '',
        }
      })

      libraryPreviewWarnings.value = Array.isArray(preview.warnings)
        ? preview.warnings.map((warning) => String(warning))
        : []
    } catch (error) {
      libraryParseError.value = resolveApiError(error, 'Unable to parse DOCX file. Please check file formatting and try again.')
    } finally {
      libraryParsing.value = false
    }
  }

  async function loadLibraryBanks() {
    if (!asBooleanValue(canManageLibraries)) return

    libraryLoading.value = true
    libraryError.value = ''

    try {
      const { data } = await questionsApi.listBanks()
      libraryQuestionBanks.value = data.banks ?? []
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to load question banks right now.')
    } finally {
      libraryLoading.value = false
    }
  }

  async function loadRemedialQueue() {
    if (!asBooleanValue(canManageLibraries)) return

    libraryReviewLoading.value = true
    libraryError.value = ''

    try {
      const { data } = await questionsApi.listRemedialQueue()
      remedialQueue.value = Array.isArray(data?.questions) ? data.questions : []
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to load the remedial review queue right now.')
    } finally {
      libraryReviewLoading.value = false
    }
  }

  async function handleSaveLibraryQuestionBank() {
    if (!canSaveLibraryQuestionBank.value) return

    librarySaving.value = true
    libraryParseError.value = ''
    libraryError.value = ''
    libraryMessage.value = ''

    const payload = {
      title: libraryForm.questionName.trim(),
      subject: libraryForm.subjectCategory.trim(),
      source_filename: libraryDocxName.value || null,
      questions: digitalizedQuestions.value.map((question, questionIndex) => ({
        item_number: Number(question.item_number ?? (questionIndex + 1)),
        text: String(question.text ?? '').trim(),
        topic_name: question.topic_name ? String(question.topic_name).trim() : null,
        question_type: String(question.question_type ?? (question.options?.length ? 'multiple_choice' : 'open_ended')),
        answer_label: question.answer_label ? String(question.answer_label).trim().toUpperCase() : null,
        answer_text: question.answer_text ? String(question.answer_text).trim() : null,
        options: (question.options ?? []).map((option, optionIndex) => ({
          label: String(option.label ?? String.fromCharCode(65 + optionIndex)).trim().toUpperCase(),
          text: String(option.text ?? '').trim(),
          is_correct: Boolean(option.is_correct),
        })),
      })),
    }

    try {
      const { data } = await questionsApi.saveBank(payload)
      closeLibraryQuestionModal()
      libraryMessage.value = data?.message ?? 'Question bank saved.'
      await Promise.all([
        loadLibraryBanks(),
        loadRemedialQueue(),
        notifyBanksChanged(),
      ])
    } catch (error) {
      libraryParseError.value = resolveApiError(error, 'Unable to save question bank.')
    } finally {
      librarySaving.value = false
    }
  }

  async function handleDeleteLibraryBank() {
    if (!selectedLibraryBank.value?.id || libraryDeleting.value) return

    libraryDeleting.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      await questionsApi.deleteBank(selectedLibraryBank.value.id)
      closeDeleteLibraryBankModal()
      libraryMessage.value = 'Question bank deleted successfully.'
      await Promise.all([
        loadLibraryBanks(),
        loadRemedialQueue(),
        notifyBanksChanged(),
      ])
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to delete question bank.')
    } finally {
      libraryDeleting.value = false
    }
  }

  async function queueBankRemedialGeneration(bankId) {
    if (!bankId || libraryGenerationSaving.value) return

    libraryGenerationSaving.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      const { data } = await questionsApi.queueRemedialGeneration(bankId)
      libraryMessage.value = data?.message ?? 'New AI remedial question generation started for this set.'
      if (showLibraryBankQuestionsModal.value && Number(selectedLibraryQuestionBank.value?.id ?? 0) === Number(bankId)) {
        selectedLibraryQuestionBank.value = data?.bank ?? selectedLibraryQuestionBank.value
      }
      if (data?.bank) {
        patchBankCounts(bankId, () => ({ ...data.bank }))
      }
      remedialQueue.value = remedialQueue.value.filter((question) => (
        queueQuestionBankId(question) !== Number(bankId)
      ))
      refreshLibraryViewsInBackground()
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to start new AI remedial question generation for this set.')
    } finally {
      libraryGenerationSaving.value = false
    }
  }

  async function updateRemedialStatus(questionId, status) {
    if (!questionId || libraryReviewSaving.value) return

    libraryReviewSaving.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      const existingQuestion = remedialQueue.value.find((question) => Number(question?.id ?? 0) === Number(questionId))
      const previousStatus = String(existingQuestion?.status ?? '')
      const { data } = await questionsApi.updateRemedialStatus(questionId, { status })
      libraryMessage.value = data?.message ?? 'Remedial question status updated.'
      if (data?.question) {
        patchRemedialQuestionLocally(data.question, previousStatus)
      }
      refreshLibraryViewsInBackground()
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to update the remedial question status.')
    } finally {
      libraryReviewSaving.value = false
    }
  }

  async function updateRemedialSetStatus(bankId, status, currentStatus = 'pending_review') {
    if (!bankId || libraryReviewSaving.value) return

    libraryReviewSaving.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      const { data } = await questionsApi.updateBankRemedialStatus(bankId, {
        status,
        current_status: currentStatus,
      })
      libraryMessage.value = data?.message ?? 'Remedial set status updated.'
      patchRemedialSetLocally(bankId, status, currentStatus)
      refreshLibraryViewsInBackground()
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to update this remedial question set.')
    } finally {
      libraryReviewSaving.value = false
    }
  }

  async function refreshRemedialQuestion(questionId) {
    if (!questionId || libraryReviewSaving.value) return

    libraryReviewSaving.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      const existingQuestion = remedialQueue.value.find((question) => Number(question?.id ?? 0) === Number(questionId))
      const previousStatus = String(existingQuestion?.status ?? '')
      const { data } = await questionsApi.refreshRemedialQuestion(questionId)
      libraryMessage.value = data?.message ?? 'AI remedial question refreshed and returned to pending review.'
      if (data?.question) {
        patchRemedialQuestionLocally(data.question, previousStatus)
      }
      refreshLibraryViewsInBackground()
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to refresh this remedial question.')
    } finally {
      libraryReviewSaving.value = false
    }
  }

  async function saveRemedialQuestionEdits() {
    const questionId = Number(selectedRemedialQuestion.value?.id ?? 0)

    if (!questionId || !canSaveRemedialEdit.value) return

    libraryRemedialEditSaving.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      const previousStatus = String(selectedRemedialQuestion.value?.status ?? '')
      const payload = {
        question_text: remedialEditForm.questionText.trim(),
        item_style: remedialEditForm.itemStyle,
        difficulty: remedialEditForm.difficulty,
        cognitive_level: remedialEditForm.cognitiveLevel.trim(),
        tested_concept: remedialEditForm.testedConcept.trim(),
        difference_from_source: remedialEditForm.differenceFromSource.trim(),
        correct_answer: remedialEditForm.correctAnswer,
        options: {
          A: remedialEditForm.options.A.trim(),
          B: remedialEditForm.options.B.trim(),
          C: remedialEditForm.options.C.trim(),
          D: remedialEditForm.options.D.trim(),
        },
      }

      const { data } = await questionsApi.updateRemedialQuestion(questionId, payload)
      closeRemedialEditModal()
      libraryMessage.value = data?.message ?? 'Remedial question updated.'
      if (data?.question) {
        patchRemedialQuestionLocally(data.question, previousStatus)
      }
      refreshLibraryViewsInBackground()
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to update this remedial question.')
    } finally {
      libraryRemedialEditSaving.value = false
    }
  }

  return {
    showLibraryQuestionModal,
    showLibraryBankQuestionsModal,
    showDeleteLibraryBankModal,
    showRemedialEditModal,
    libraryLoading,
    libraryReviewLoading,
    libraryBankQuestionsLoading,
    librarySaving,
    libraryDeleting,
    libraryReviewSaving,
    libraryGenerationSaving,
    libraryRemedialEditSaving,
    libraryError,
    libraryMessage,
    libraryParsing,
    libraryParseError,
    libraryDocxName,
    libraryFileInputKey,
    libraryQuestionBanks,
    selectedLibraryBank,
    selectedLibraryQuestionBank,
    selectedRemedialQuestion,
    libraryBankReviewMode,
    libraryPreviewWarnings,
    groupedLibraryPreviewWarnings,
    digitalizedQuestions,
    libraryBankQuestions,
    libraryBankRemedialQuestions,
    libraryBankQuestionViewTab,
    libraryBankRemedialStatusFilter,
    libraryBankRemedialStatusSummary,
    libraryBankRemedialGroups,
    remedialQueue,
    remedialQueueGroups,
    libraryForm,
    remedialEditForm,
    librarySubjectCategories,
    remedialItemStyleOptions,
    remedialDifficultyOptions,
    canSaveLibraryQuestionBank,
    canSaveRemedialEdit,
    openLibraryQuestionModal,
    closeLibraryQuestionModal,
    openLibraryBankQuestionsModal,
    closeLibraryBankQuestionsModal,
    openRemedialEditModal,
    closeRemedialEditModal,
    setLibraryBankQuestionViewTab,
    setLibraryBankRemedialStatusFilter,
    openDeleteLibraryBankModal,
    closeDeleteLibraryBankModal,
    handleLibraryDocxChange,
    loadLibraryBanks,
    loadRemedialQueue,
    handleSaveLibraryQuestionBank,
    handleDeleteLibraryBank,
    queueBankRemedialGeneration,
    updateRemedialStatus,
    updateRemedialSetStatus,
    refreshRemedialQuestion,
    saveRemedialQuestionEdits,
  }
}
