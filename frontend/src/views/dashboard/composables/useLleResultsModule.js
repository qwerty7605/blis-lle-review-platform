import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useDashboardDataServices } from './useDashboardDataServices'
import { useLiveRefresh } from '@/utils/liveRefresh'

const SEARCH_DEBOUNCE_MS = 300
const PAGE_SIZE = 30

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
  return new Date().getFullYear()
}

export function useLleResultsModule() {
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
  const studentQuery = ref('')
  const studentMatches = ref([])
  const studentLookupLoading = ref(false)
  const saving = ref(false)
  const saveError = ref('')
  const saveNotice = ref('')

  let listToken = 0
  let listTimer = null
  let lookupToken = 0
  let lookupTimer = null

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
        exam_year: filters.exam_year || undefined,
        result: filters.result || undefined,
        page: page.value,
        per_page: PAGE_SIZE,
      })

      if (token !== listToken) return

      results.value = Array.isArray(data?.results) ? data.results : []
      meta.value = data?.meta ?? meta.value
    } catch (error) {
      if (token !== listToken || silent) return
      resultsError.value = firstApiError(error, 'Unable to load recorded licensure results.')
      results.value = []
    } finally {
      if (token === listToken && !silent) resultsLoading.value = false
    }
  }

  // Read again when someone comes back to this page.
  useLiveRefresh(() => loadResults({ silent: true }), {
    when: () => !resultsLoading.value && !saving.value,
  })

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
  }

  function clearSelectedStudent() {
    selectedStudent.value = null
    form.user_id = null
  }

  function resetForm() {
    clearSelectedStudent()
    form.exam_year = currentExamYear()
    form.taken_at = ''
    form.result = 'pass'
    form.rating = ''
    form.remarks = ''
  }

  const canSubmit = computed(() => Boolean(form.user_id) && Boolean(form.exam_year) && !saving.value)

  async function submitResult() {
    if (!canSubmit.value) return

    saving.value = true
    saveError.value = ''
    saveNotice.value = ''

    try {
      const { data } = await services.recordLleResult({
        user_id: form.user_id,
        exam_year: Number(form.exam_year),
        taken_at: form.taken_at || null,
        result: form.result,
        // An empty rating field means "not published", not zero.
        rating: form.rating === '' ? null : Number(form.rating),
        remarks: form.remarks || null,
      })

      const name = data?.result?.student?.name ?? 'Student'
      saveNotice.value = `Recorded ${form.result.toUpperCase()} for ${name} (${form.exam_year}).`
      resetForm()
      page.value = 1
      await loadResults()
    } catch (error) {
      saveError.value = firstApiError(error, 'Unable to record that result.')
    } finally {
      saving.value = false
    }
  }

  async function removeResult(result) {
    if (!result?.id) return

    resultsError.value = ''

    try {
      await services.removeLleResult(result.id)
      await loadResults()
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

  watch(() => [filters.exam_year, filters.result], () => {
    page.value = 1
    loadResults()
  })

  watch(page, () => loadResults())

  watch(studentQuery, () => {
    if (lookupTimer) clearTimeout(lookupTimer)
    lookupTimer = setTimeout(() => lookupStudents(), SEARCH_DEBOUNCE_MS)
  })

  onMounted(() => loadResults())

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
    removeResult,
  }
}
