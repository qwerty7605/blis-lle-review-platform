import { computed, onUnmounted, reactive, ref } from 'vue'
import { questionsApi } from '@/api/questions.api'
import { useLiveRefresh } from '@/utils/liveRefresh'
import { LIBRARY_SUBJECT_CATEGORIES } from '@/constants/librarySubjects'

// AI item mapping runs in a background queue worker, so a bank's
// mapping_status can flip from queued/processing to completed at any moment
// with nothing the browser did. Polled at the same cadence the Review Bot
// generator uses for its own "check back shortly" background jobs.
const MAPPING_POLL_INTERVAL_MS = 4000
const MAPPING_IN_FLIGHT_STATUSES = ['queued', 'processing']

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
  const showEditLibraryBankModal = ref(false)

  const libraryLoading = ref(false)
  const libraryBankQuestionsLoading = ref(false)
  const librarySaving = ref(false)
  const libraryDeleting = ref(false)
  const libraryMappingSaving = ref(false)
  const libraryBankEditSaving = ref(false)
  const questionEditSaving = ref(false)
  const libraryError = ref('')
  const libraryMessage = ref('')

  const libraryParsing = ref(false)
  const libraryParseError = ref('')
  const libraryDocxName = ref('')
  const libraryFileInputKey = ref(0)

  const libraryQuestionBanks = ref([])
  const selectedLibraryBank = ref(null)
  const selectedLibraryQuestionBank = ref(null)
  const editingLibraryBank = ref(null)
  const libraryPreviewWarnings = ref([])
  const digitalizedQuestions = ref([])
  const libraryBankQuestions = ref([])

  const libraryBankEditForm = reactive({ title: '', subject: '' })

  const editingQuestionId = ref(null)
  const questionEditTopicOptions = ref([])
  const questionEditSubtopicOptions = ref([])
  const questionEditForm = reactive({
    question_text: '',
    question_type: 'multiple_choice',
    options: [],
    topic_name: '',
    subtopic_name: '',
    difficulty: '',
    cognitive_level: '',
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

  function refreshLibraryViewsInBackground() {
    void Promise.all([
      loadLibraryBanks(),
      showLibraryBankQuestionsModal.value
        ? loadLibraryBankQuestions(selectedLibraryQuestionBank.value, { preserveViewState: true })
        : Promise.resolve(),
    ])
  }

  // Live-updates mapping_status without a manual refresh: self-reschedules
  // (rather than setInterval) so a slow request never overlaps the next poll,
  // and stops on its own once nothing is left queued/processing -- no polling
  // forever in the background once a set finishes mapping.
  const hasMappingInFlight = computed(() => (
    libraryQuestionBanks.value.some((bank) => MAPPING_IN_FLIGHT_STATUSES.includes(bank?.mapping_status))
  ))

  let mappingPollTimeoutId = null

  function stopMappingPoll() {
    if (mappingPollTimeoutId !== null) {
      clearTimeout(mappingPollTimeoutId)
      mappingPollTimeoutId = null
    }
  }

  function scheduleMappingPollIfNeeded() {
    stopMappingPoll()

    if (!hasMappingInFlight.value) return

    // loadLibraryBanks() (always the first thing refreshLibraryViewsInBackground
    // does) calls this same function again once fresh data is in, which is what
    // actually re-arms the next tick -- this timeout only needs to kick that off.
    mappingPollTimeoutId = setTimeout(() => {
      mappingPollTimeoutId = null
      void refreshLibraryViewsInBackground()
    }, MAPPING_POLL_INTERVAL_MS)
  }

  onUnmounted(stopMappingPoll)

  const libraryForm = reactive({
    questionName: '',
    subjectCategory: '',
    file: null,
  })

  const librarySubjectCategories = LIBRARY_SUBJECT_CATEGORIES
  const libraryDifficultyOptions = ['easy', 'medium', 'hard']
  const libraryCognitiveLevelOptions = ['recall', 'comprehension', 'application', 'analysis']
  const libraryQuestionTypeOptions = ['multiple_choice', 'true_false', 'open_ended']

  // What the file picked last was detected as ('' when it could not be told),
  // and whether the teacher has picked the subject themselves. The detection
  // fills the Core Subject field only until they have: it is sometimes wrong,
  // and their choice has to outlast picking another file.
  const libraryDetectedSubject = ref('')
  const librarySubjectChosen = ref(false)

  function markLibrarySubjectChosen() {
    librarySubjectChosen.value = true
  }

  // The line under the Core Subject field: where its value came from.
  const librarySubjectNote = computed(() => {
    if (libraryParsing.value) return 'Reading the file...'

    const detected = libraryDetectedSubject.value
    const hasQuestions = digitalizedQuestions.value.length > 0

    if (detected && libraryForm.subjectCategory === detected) {
      return 'Detected from the file. Change it if it is wrong.'
    }
    if (detected) return `The file was detected as ${detected}.`
    if (hasQuestions && !libraryForm.subjectCategory) {
      return 'The subject could not be told from the file. Choose it here.'
    }
    if (!hasQuestions && !librarySubjectChosen.value) {
      return 'Detected from the file once you pick one. You can change it.'
    }

    return ''
  })

  // The name is taken from the filename, so a teacher never has to type it.
  const canSaveLibraryQuestionBank = computed(() => (
    !librarySaving.value
    && !libraryParsing.value
    && libraryForm.questionName.trim().length > 0
    && digitalizedQuestions.value.length > 0
  ))

  function deriveQuestionNameFromFilename(filename) {
    return String(filename ?? '')
      .replace(/\.docx$/i, '')
      .replace(/[_-]+/g, ' ')
      .trim()
  }

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
    libraryDetectedSubject.value = ''
    librarySubjectChosen.value = false
    libraryForm.file = null
    libraryDocxName.value = ''
    libraryParseError.value = ''
    libraryParsing.value = false
    libraryPreviewWarnings.value = []
    digitalizedQuestions.value = []
    libraryFileInputKey.value += 1
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
    libraryBankQuestionsLoading.value = false
  }

  async function loadLibraryBankQuestions(bank) {
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
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to load the questions for this bank right now.')
    } finally {
      libraryBankQuestionsLoading.value = false
    }
  }

  async function openLibraryBankQuestionsModal(bank) {
    if (!bank?.id) return

    showLibraryBankQuestionsModal.value = true
    selectedLibraryQuestionBank.value = bank
    libraryBankQuestions.value = []

    try {
      await loadLibraryBankQuestions(bank)
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to load the questions for this bank right now.')
      closeLibraryBankQuestionsModal()
    }
  }

  function closeLibraryBankQuestionsModal() {
    showLibraryBankQuestionsModal.value = false
    cancelEditingQuestion()
    resetLibraryBankQuestionState()
  }

  function openDeleteLibraryBankModal(bank) {
    if (!bank?.id) return

    // The dialog shows libraryError itself; an older one must not greet it.
    libraryError.value = ''
    selectedLibraryBank.value = bank
    showDeleteLibraryBankModal.value = true
  }

  function closeDeleteLibraryBankModal() {
    showDeleteLibraryBankModal.value = false
    selectedLibraryBank.value = null
  }

  function openEditLibraryBankModal(bank) {
    if (!bank?.id) return

    editingLibraryBank.value = bank
    libraryBankEditForm.title = bank.title || ''
    libraryBankEditForm.subject = bank.subject || ''
    showEditLibraryBankModal.value = true
  }

  function closeEditLibraryBankModal() {
    showEditLibraryBankModal.value = false
    editingLibraryBank.value = null
  }

  // Resolves to whether the set was saved.
  async function handleUpdateLibraryBank() {
    if (!editingLibraryBank.value?.id || libraryBankEditSaving.value) return false
    if (!libraryBankEditForm.title.trim()) return false

    libraryBankEditSaving.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      const { data } = await questionsApi.updateBank(editingLibraryBank.value.id, {
        title: libraryBankEditForm.title.trim(),
        subject: libraryBankEditForm.subject.trim() || null,
      })
      libraryMessage.value = data?.message ?? 'Question bank updated.'
      closeEditLibraryBankModal()
      await Promise.all([loadLibraryBanks(), notifyBanksChanged()])
      return true
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to update this question bank.')
      return false
    } finally {
      libraryBankEditSaving.value = false
    }
  }

  const libraryBankEditDirty = computed(() => {
    const bank = editingLibraryBank.value
    if (!bank) return false

    return libraryBankEditForm.title.trim() !== (bank.title || '').trim()
      || libraryBankEditForm.subject !== (bank.subject || '')
  })

  // From the Edit dialog to the set's questions, where each one's wording,
  // answer and mapping (topic, subtopic, difficulty, cognitive level) are
  // edited. A changed name or subject is saved on the way: the topics on
  // offer for a question are those of the set's subject.
  async function editLibraryBankQuestions() {
    const bank = editingLibraryBank.value
    if (!bank?.id || libraryBankEditSaving.value) return

    if (libraryBankEditDirty.value) {
      if (!(await handleUpdateLibraryBank())) return
    } else {
      closeEditLibraryBankModal()
    }

    await openLibraryBankQuestionsModal(bank)
  }

  function cancelEditingQuestion() {
    editingQuestionId.value = null
    questionEditTopicOptions.value = []
    questionEditSubtopicOptions.value = []
  }

  async function loadQuestionEditTopicOptions(subject) {
    if (!subject) {
      questionEditTopicOptions.value = []
      return
    }

    try {
      const { data } = await questionsApi.listTopics(subject)
      questionEditTopicOptions.value = data?.topics ?? []
    } catch {
      questionEditTopicOptions.value = []
    }
  }

  async function loadQuestionEditSubtopicOptions(subject, topic) {
    if (!subject || !topic) {
      questionEditSubtopicOptions.value = []
      return
    }

    try {
      const { data } = await questionsApi.listTopics(subject, topic)
      questionEditSubtopicOptions.value = data?.subtopics ?? []
    } catch {
      questionEditSubtopicOptions.value = []
    }
  }

  async function startEditingQuestion(question) {
    if (!question?.id) return

    editingQuestionId.value = question.id
    questionEditForm.question_text = question.question_text || ''
    questionEditForm.question_type = question.question_type || 'multiple_choice'
    questionEditForm.options = Array.isArray(question.options)
      ? question.options.map((option) => ({
          label: option.option_label,
          text: option.option_text,
          is_correct: Boolean(option.is_correct),
        }))
      : []
    questionEditForm.topic_name = question.topic?.name || ''
    questionEditForm.subtopic_name = question.subtopic?.name || ''
    questionEditForm.difficulty = question.difficulty || ''
    questionEditForm.cognitive_level = question.cognitive_level || ''

    const subject = selectedLibraryQuestionBank.value?.subject
    await loadQuestionEditTopicOptions(subject)
    if (questionEditForm.topic_name) {
      await loadQuestionEditSubtopicOptions(subject, questionEditForm.topic_name)
    }
  }

  async function handleQuestionEditTopicChange() {
    questionEditForm.subtopic_name = ''
    await loadQuestionEditSubtopicOptions(selectedLibraryQuestionBank.value?.subject, questionEditForm.topic_name)
  }

  function addQuestionEditOption() {
    if (questionEditForm.options.length >= 8) return

    questionEditForm.options.push({
      label: String.fromCharCode(65 + questionEditForm.options.length),
      text: '',
      is_correct: questionEditForm.options.length === 0,
    })
  }

  function removeQuestionEditOption(index) {
    questionEditForm.options.splice(index, 1)
  }

  function setQuestionEditCorrectOption(index) {
    questionEditForm.options.forEach((option, optionIndex) => {
      option.is_correct = optionIndex === index
    })
  }

  async function saveEditingQuestion() {
    if (!editingQuestionId.value || questionEditSaving.value) return

    questionEditSaving.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    const isOpenEnded = questionEditForm.question_type === 'open_ended'

    const payload = {
      question_text: questionEditForm.question_text.trim(),
      question_type: questionEditForm.question_type,
      options: isOpenEnded
        ? []
        : questionEditForm.options.map((option) => ({
            label: option.label,
            text: (option.text || '').trim(),
            is_correct: Boolean(option.is_correct),
          })),
      topic_name: questionEditForm.topic_name || null,
      subtopic_name: questionEditForm.subtopic_name || null,
      difficulty: questionEditForm.difficulty || null,
      cognitive_level: questionEditForm.cognitive_level || null,
    }

    try {
      const { data } = await questionsApi.updateQuestion(editingQuestionId.value, payload)
      libraryMessage.value = data?.message ?? 'Question updated.'
      cancelEditingQuestion()
      await loadLibraryBankQuestions(selectedLibraryQuestionBank.value)
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to update this question.')
    } finally {
      questionEditSaving.value = false
    }
  }

  async function handleLibraryDocxChange(event) {
    const file = event.target?.files?.[0] ?? null

    libraryParseError.value = ''
    libraryPreviewWarnings.value = []
    digitalizedQuestions.value = []
    libraryForm.file = null
    libraryDocxName.value = ''

    // The last file's detection says nothing about this one.
    libraryDetectedSubject.value = ''
    if (!librarySubjectChosen.value) libraryForm.subjectCategory = ''

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

    // Named after the file right away -- don't wait on the parse to finish.
    // Always after this file: the dialog has no name field, so a name already
    // there can only be left over from a file picked before this one, and
    // keeping it saved the bank under the wrong file's name.
    libraryForm.questionName = deriveQuestionNameFromFilename(file.name)

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

      // Fill in the detected subject, unless the teacher has already picked.
      libraryDetectedSubject.value = String(preview.suggested_subject ?? '')
      if (!librarySubjectChosen.value) {
        libraryForm.subjectCategory = libraryDetectedSubject.value
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

  // silent: the quiet refresh behind useLiveRefresh (below).
  async function loadLibraryBanks({ silent = false } = {}) {
    if (!asBooleanValue(canManageLibraries)) return

    if (!silent) {
      libraryLoading.value = true
      libraryError.value = ''
    }

    try {
      const { data } = await questionsApi.listBanks()
      libraryQuestionBanks.value = data.banks ?? []
      scheduleMappingPollIfNeeded()
    } catch (error) {
      if (!silent) libraryError.value = resolveApiError(error, 'Unable to load question banks right now.')
    } finally {
      if (!silent) libraryLoading.value = false
    }
  }

  // Read again when the teacher comes back to this page. Not under a dialog:
  // each of them is about one of the sets in this list.
  useLiveRefresh(() => loadLibraryBanks({ silent: true }), {
    when: () => !libraryLoading.value
      && !librarySaving.value
      && !showLibraryQuestionModal.value
      && !showLibraryBankQuestionsModal.value
      && !showEditLibraryBankModal.value
      && !showDeleteLibraryBankModal.value,
  })

  async function handleSaveLibraryQuestionBank() {
    if (!canSaveLibraryQuestionBank.value) return

    librarySaving.value = true
    libraryParseError.value = ''
    libraryError.value = ''
    libraryMessage.value = ''

    const payload = {
      title: libraryForm.questionName.trim(),
      subject: libraryForm.subjectCategory.trim() || null,
      // Item mapping is always auto now -- the local classifier's own
      // confidence gate already forces a weak guess into pending_review
      // regardless of mode, so there's nothing left for a teacher to pick.
      ai_tagging_mode: 'auto',
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
        notifyBanksChanged(),
      ])
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to delete question bank.')
    } finally {
      libraryDeleting.value = false
    }
  }

  async function queueBankMapping(bankId, mode) {
    if (!bankId || libraryMappingSaving.value) return

    libraryMappingSaving.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      const { data } = await questionsApi.queueMapping(bankId, mode)
      libraryMessage.value = data?.message ?? 'AI item mapping started for this set.'
      if (showLibraryBankQuestionsModal.value && Number(selectedLibraryQuestionBank.value?.id ?? 0) === Number(bankId)) {
        selectedLibraryQuestionBank.value = data?.bank ?? selectedLibraryQuestionBank.value
      }
      if (data?.bank) {
        patchBankCounts(bankId, () => ({ ...data.bank }))
      }
      refreshLibraryViewsInBackground()
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to start AI item mapping for this set.')
    } finally {
      libraryMappingSaving.value = false
    }
  }

  async function confirmBankTags(bankId) {
    if (!bankId || libraryMappingSaving.value) return

    libraryMappingSaving.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      const { data } = await questionsApi.updateBankTagsStatus(bankId)
      libraryMessage.value = data?.message ?? 'Suggested tags confirmed.'
      refreshLibraryViewsInBackground()
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to confirm suggested tags for this set.')
    } finally {
      libraryMappingSaving.value = false
    }
  }

  return {
    showLibraryQuestionModal,
    showLibraryBankQuestionsModal,
    showDeleteLibraryBankModal,
    showEditLibraryBankModal,
    libraryLoading,
    libraryBankQuestionsLoading,
    librarySaving,
    libraryDeleting,
    libraryMappingSaving,
    libraryBankEditSaving,
    questionEditSaving,
    libraryError,
    libraryMessage,
    libraryParsing,
    libraryParseError,
    libraryDocxName,
    libraryFileInputKey,
    libraryQuestionBanks,
    selectedLibraryBank,
    selectedLibraryQuestionBank,
    editingLibraryBank,
    libraryPreviewWarnings,
    groupedLibraryPreviewWarnings,
    digitalizedQuestions,
    libraryBankQuestions,
    libraryForm,
    librarySubjectNote,
    markLibrarySubjectChosen,
    libraryBankEditForm,
    librarySubjectCategories,
    libraryDifficultyOptions,
    libraryCognitiveLevelOptions,
    libraryQuestionTypeOptions,
    canSaveLibraryQuestionBank,
    editingQuestionId,
    questionEditForm,
    questionEditTopicOptions,
    questionEditSubtopicOptions,
    openLibraryQuestionModal,
    closeLibraryQuestionModal,
    openLibraryBankQuestionsModal,
    closeLibraryBankQuestionsModal,
    openDeleteLibraryBankModal,
    closeDeleteLibraryBankModal,
    openEditLibraryBankModal,
    closeEditLibraryBankModal,
    handleUpdateLibraryBank,
    libraryBankEditDirty,
    editLibraryBankQuestions,
    handleLibraryDocxChange,
    loadLibraryBanks,
    handleSaveLibraryQuestionBank,
    handleDeleteLibraryBank,
    queueBankMapping,
    confirmBankTags,
    startEditingQuestion,
    cancelEditingQuestion,
    handleQuestionEditTopicChange,
    addQuestionEditOption,
    removeQuestionEditOption,
    setQuestionEditCorrectOption,
    saveEditingQuestion,
  }
}
