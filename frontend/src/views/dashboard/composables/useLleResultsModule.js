import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { useDashboardDataServices } from './useDashboardDataServices'
import { useLiveRefresh } from '@/utils/liveRefresh'
import { decimalProblem, examDateProblem, isFullYear, lengthProblem, useFieldChecks, yearProblem } from '@/utils/fieldChecks'
import {
  belowPassingProblem,
  currentYear,
  DID_NOT_TAKE,
  examYearProblem,
  RESULT_LABELS,
  replacedReason,
  sittingOrderProblem,
  unusualReasons,
} from '@/utils/lleResultChecks'

const SEARCH_DEBOUNCE_MS = 300
const PAGE_SIZE = 30
// Every result one student has: a handful at most (one per sitting).
const STUDENT_RESULTS_LIMIT = 100

// Candidates are usually graduates, so the picker has to look past the enrolled
// default the directory applies.
const PICKER_ARCHIVE_STATE = 'all'
const PICKER_LIMIT = 8

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function currentExamYear() {
  return currentYear()
}

// onResultsChanged: called after this module saves or removes a result.
export function useLleResultsModule({ onResultsChanged = () => {} } = {}) {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const resultsLoading = ref(false)
  const resultsError = ref('')
  const results = ref([])
  const meta = ref({ current_page: 1, last_page: 1, per_page: PAGE_SIZE, total: 0, from: 0, to: 0 })

  const filters = reactive({
    search: '',
    exam_year: '',
    result: '',
  })
  const page = ref(1)

  // --- the record form -------------------------------------------------

  const form = reactive({
    user_id: null,
    exam_year: currentExamYear(),
    taken_at: '',
    result: 'pass',
    rating: '',
    remarks: '',
  })

  const selectedStudent = ref(null)
  // Everything on record for the chosen student, every year: a new result
  // has to agree with it (nothing after a pass), and one for a year already
  // on record replaces it.
  const selectedStudentResults = ref([])
  const studentQuery = ref('')
  const studentMatches = ref([])
  const studentLookupLoading = ref(false)
  const saving = ref(false)
  const saveError = ref('')
  const saveNotice = ref('')
  // LleResultRules::PASSING_AVERAGES, from the server with the results.
  const passingAverages = ref({})
  // The "Check before saving" dialog (LicensureSaveCheck.vue).
  const confirmOpen = ref(false)

  let listToken = 0
  let listTimer = null
  let lookupToken = 0
  let lookupTimer = null
  let studentResultsToken = 0

  // silent: the quiet refresh behind useLiveRefresh (below).
  async function loadResults({ silent = false } = {}) {
    const token = ++listToken

    if (!silent) {
      resultsLoading.value = true
      resultsError.value = ''
    }

    try {
      const { data } = await services.getLleResults({
        search: filters.search.trim() || undefined,
        exam_year: isFullYear(filters.exam_year) ? filters.exam_year : undefined,
        result: filters.result || undefined,
        page: page.value,
        per_page: PAGE_SIZE,
      })

      if (token !== listToken) return

      results.value = Array.isArray(data?.results) ? data.results : []
      meta.value = data?.meta ?? meta.value
      if (data?.passing_averages) passingAverages.value = data.passing_averages
    } catch (error) {
      if (token !== listToken || silent) return
      resultsError.value = firstApiError(error, 'Unable to load recorded licensure results.')
      results.value = []
    } finally {
      if (token === listToken && !silent) resultsLoading.value = false
    }
  }

  // --- the Exam Outcome model these results teach ---------------------------

  const modelStatus = ref(null)
  const modelLoading = ref(false)
  const modelBusy = ref(false)
  const modelError = ref('')
  const modelNotice = ref('')
  // Retraining and changing the version in use are an administrator's.
  const canManageModel = computed(() => String(auth.user?.role ?? '').toLowerCase() === 'admin')

  // silent: after a result is saved or removed, the counts just move.
  async function loadModelStatus({ silent = false } = {}) {
    if (!silent) {
      modelLoading.value = true
      modelError.value = ''
    }

    try {
      const { data } = await services.getLetModelStatus()
      modelStatus.value = data
    } catch (error) {
      if (!silent) modelError.value = firstApiError(error, 'Unable to load the Estimated Outcome model.')
    } finally {
      if (!silent) modelLoading.value = false
    }
  }

  async function changeModel(request, fallbackMessage) {
    modelBusy.value = true
    modelError.value = ''
    modelNotice.value = ''

    try {
      const { data } = await request()
      modelStatus.value = data
      modelNotice.value = data?.message ?? ''
    } catch (error) {
      // A retraining that was not allowed to start still says where things
      // stand.
      if (Array.isArray(error?.response?.data?.versions)) modelStatus.value = error.response.data
      modelError.value = firstApiError(error, fallbackMessage)
    } finally {
      modelBusy.value = false
    }
  }

  function retrainModel() {
    return changeModel(() => services.retrainLetModel(), 'Unable to retrain the Estimated Outcome model.')
  }

  function activateModelVersion(version) {
    if (typeof window !== 'undefined') {
      const confirmed = window.confirm(`Use version ${version} of the Estimated Outcome model from now on?`)
      if (!confirmed) return Promise.resolve()
    }

    return changeModel(() => services.activateLetModel(version), 'Unable to switch to that version.')
  }

  // Read again when someone comes back to this page.
  // While a retraining runs on the queue, look again every 5 seconds until
  // the server says it is done.
  let trainingTimer = null
  watch(() => modelStatus.value?.training, (training) => {
    clearInterval(trainingTimer)
    trainingTimer = training ? setInterval(() => loadModelStatus({ silent: true }), 5000) : null
  })
  onUnmounted(() => clearInterval(trainingTimer))

  useLiveRefresh(() => Promise.all([
    loadResults({ silent: true }),
    loadModelStatus({ silent: true }),
    selectedStudent.value ? loadSelectedStudentResults(selectedStudent.value.id) : null,
  ]), {
    when: () => !resultsLoading.value && !saving.value && !modelBusy.value && !confirmOpen.value,
  })

  // The checks below read these; until they arrive the server still
  // refuses what they would have, the page just says it later.
  async function loadSelectedStudentResults(userId) {
    const token = ++studentResultsToken

    if (!userId) {
      selectedStudentResults.value = []
      return
    }

    try {
      const { data } = await services.getLleResults({ user_id: userId, per_page: STUDENT_RESULTS_LIMIT })

      if (token !== studentResultsToken) return

      selectedStudentResults.value = Array.isArray(data?.results) ? data.results : []
      if (data?.passing_averages) passingAverages.value = data.passing_averages
    } catch {
      if (token === studentResultsToken) selectedStudentResults.value = []
    }
  }

  async function lookupStudents() {
    const term = studentQuery.value.trim()

    if (term.length < 2) {
      studentMatches.value = []
      return
    }

    const token = ++lookupToken
    studentLookupLoading.value = true

    try {
      const { data } = await services.getStudentsDirectory({
        search: term,
        archive_state: PICKER_ARCHIVE_STATE,
        per_page: PICKER_LIMIT,
      })

      if (token !== lookupToken) return
      studentMatches.value = Array.isArray(data?.students) ? data.students : []
    } catch {
      if (token !== lookupToken) return
      studentMatches.value = []
    } finally {
      if (token === lookupToken) studentLookupLoading.value = false
    }
  }

  function selectStudent(student) {
    selectedStudent.value = student
    form.user_id = student?.id ?? null
    studentMatches.value = []
    studentQuery.value = ''
    selectedStudentResults.value = []
    loadSelectedStudentResults(student?.id ?? null)
  }

  function clearSelectedStudent() {
    selectedStudent.value = null
    form.user_id = null
    studentResultsToken++
    selectedStudentResults.value = []
  }

  function resetForm() {
    clearSelectedStudent()
    form.exam_year = currentExamYear()
    form.taken_at = ''
    form.result = 'pass'
    form.rating = ''
    form.remarks = ''
  }

  const canSubmit = computed(() => !saving.value)

  // Someone who did not sit has no date taken and no general average: the
  // page turns both fields off, and they are emptied here so nothing typed
  // before is sent (the server refuses either).
  watch(() => form.result, (result) => {
    if (result !== DID_NOT_TAKE) return
    form.taken_at = ''
    form.rating = ''
  })

  // LleResultController::store and LleResultRules (lleResultChecks.js).
  const resultChecks = useFieldChecks('lle-result', {
    user_id: () => (form.user_id ? '' : 'Search for the student and choose them from the list.'),
    exam_year: () => yearProblem(form.exam_year, { label: 'Exam year', required: true }) || examYearProblem(form.exam_year),
    taken_at: () => examDateProblem(form.taken_at, form.exam_year),
    result: () => (selectedStudent.value
      ? sittingOrderProblem({
        name: selectedStudent.value.name,
        examYear: form.exam_year,
        result: form.result,
        otherResults: selectedStudentResults.value,
      })
      : ''),
    rating: () => decimalProblem(form.rating, { label: 'General average', min: 0, max: 100 })
      || belowPassingProblem({ result: form.result, rating: form.rating, examYear: form.exam_year, passingAverages: passingAverages.value }),
    remarks: () => lengthProblem(form.remarks, { label: 'Remarks', max: 1000 }),
  })

  // What can be right but is usually a slip: shown under the form as it is
  // filled in, and asked about once more when Record is pressed.
  const flagReasons = computed(() => {
    if (!selectedStudent.value) return []

    const onRecord = selectedStudentResults.value.find((result) => Number(result.exam_year) === Number(form.exam_year))
    const replaced = onRecord
      ? replacedReason({
        examYear: form.exam_year,
        recordedResult: onRecord.result,
        recordedRating: onRecord.rating,
        result: form.result,
        rating: form.rating,
        whole: true,
      })
      : ''

    return [
      ...(replaced ? [replaced] : []),
      ...unusualReasons({
        result: form.result,
        rating: form.rating,
        examYear: form.exam_year,
        passingAverages: passingAverages.value,
        studentStatus: selectedStudent.value.student_status,
      }),
    ]
  })

  const confirmItems = computed(() => (selectedStudent.value
    ? [{ id: selectedStudent.value.id, name: selectedStudent.value.name, reasons: flagReasons.value }]
    : []))

  async function submitResult() {
    if (!canSubmit.value) return
    if (!resultChecks.check()) return

    if (flagReasons.value.length > 0) {
      confirmOpen.value = true
      return
    }

    await saveResult()
  }

  function cancelConfirm() {
    confirmOpen.value = false
  }

  async function confirmSave() {
    confirmOpen.value = false
    await saveResult()
  }

  async function saveResult() {
    saving.value = true
    saveError.value = ''
    saveNotice.value = ''

    try {
      const { data, status } = await services.recordLleResult({
        user_id: form.user_id,
        exam_year: Number(form.exam_year),
        taken_at: form.taken_at || null,
        result: form.result,
        // An empty rating field means "not published", not zero.
        rating: form.rating === '' ? null : Number(form.rating),
        remarks: form.remarks || null,
      })

      const name = data?.result?.student?.name ?? 'Student'
      const label = RESULT_LABELS[form.result] ?? form.result
      // 200 rather than 201: the year was already on record and is replaced.
      saveNotice.value = status === 200
        ? `Replaced the ${form.exam_year} result for ${name} with "${label}".`
        : `Recorded "${label}" for ${name} (${form.exam_year}).`
      resetForm()
      resultChecks.reset()
      page.value = 1
      onResultsChanged()
      await Promise.all([loadResults(), loadModelStatus({ silent: true })])
    } catch (error) {
      if (!resultChecks.takeServerErrors(error)) saveError.value = firstApiError(error, 'Unable to record that result.')
    } finally {
      saving.value = false
    }
  }

  async function removeResult(result) {
    if (!result?.id) return

    resultsError.value = ''

    try {
      await services.removeLleResult(result.id)
      onResultsChanged()
      await Promise.all([
        loadResults(),
        loadModelStatus({ silent: true }),
        // It may be what the One Student form is checking against.
        selectedStudent.value ? loadSelectedStudentResults(selectedStudent.value.id) : null,
      ])
    } catch (error) {
      resultsError.value = firstApiError(error, 'Unable to remove that result.')
    }
  }

  // --- pagination ------------------------------------------------------

  const canGoToPreviousPage = computed(() => meta.value.current_page > 1)
  const canGoToNextPage = computed(() => meta.value.current_page < meta.value.last_page)

  const rangeLabel = computed(() => {
    const { from, to, total } = meta.value
    if (!total || total < 1) return 'No results recorded yet'
    return `Showing ${from}-${to} of ${total} result${total === 1 ? '' : 's'}`
  })

  function goToPage(target) {
    const next = Number(target)
    if (!Number.isFinite(next)) return
    page.value = Math.min(Math.max(1, Math.trunc(next)), Math.max(1, meta.value.last_page))
  }

  watch(() => filters.search, () => {
    page.value = 1
    if (listTimer) clearTimeout(listTimer)
    listTimer = setTimeout(() => loadResults(), SEARCH_DEBOUNCE_MS)
  })

  // A year is used once it is four digits; until then it is said under the
  // field (the server refuses "20" with a 422).
  const filterChecks = useFieldChecks('lle-filter', {
    exam_year: () => yearProblem(filters.exam_year, { label: 'Exam year' }),
  })

  watch(() => [isFullYear(filters.exam_year) ? filters.exam_year : '', filters.result], () => {
    page.value = 1
    loadResults()
  })

  watch(page, () => loadResults())

  watch(studentQuery, () => {
    if (lookupTimer) clearTimeout(lookupTimer)
    lookupTimer = setTimeout(() => lookupStudents(), SEARCH_DEBOUNCE_MS)
  })

  onMounted(() => {
    loadResults()
    loadModelStatus()
  })

  onUnmounted(() => {
    if (listTimer) clearTimeout(listTimer)
    if (lookupTimer) clearTimeout(lookupTimer)
  })

  return {
    resultsLoading,
    resultsError,
    results,
    meta,
    filters,
    rangeLabel,
    canGoToPreviousPage,
    canGoToNextPage,
    goToPage,
    loadResults,
    form,
    resultChecks,
    filterChecks,
    selectedStudent,
    studentQuery,
    studentMatches,
    studentLookupLoading,
    selectStudent,
    clearSelectedStudent,
    canSubmit,
    saving,
    saveError,
    saveNotice,
    submitResult,
    flagReasons,
    confirmOpen,
    confirmItems,
    cancelConfirm,
    confirmSave,
    removeResult,
    loadModelStatus,
    modelStatus,
    modelLoading,
    modelBusy,
    modelError,
    modelNotice,
    canManageModel,
    retrainModel,
    activateModelVersion,
  }
}
