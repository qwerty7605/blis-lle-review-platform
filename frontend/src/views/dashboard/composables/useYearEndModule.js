import { computed, onMounted, ref } from 'vue'
import { yearEndApi } from '@/api/yearEnd.api'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

const ORDINALS = { 1: '1st', 2: '2nd', 3: '3rd' }

export function yearLevelLabel(value) {
  const level = Number(value)
  if (!Number.isFinite(level) || level < 1) return 'No year level'

  return `${ORDINALS[level] ?? `${level}th`} Year`
}

// A student ID is 7 to 10 digits wherever one is typed in, so that is what
// counts as one in a pasted or uploaded list, whatever sits around it --
// names, commas, a header row. Runs of digits filtered by length rather than
// a lookbehind pattern, which older Safari cannot parse at all.
function studentIdsIn(text) {
  return (String(text ?? '').match(/\d+/g) ?? []).filter((run) => run.length >= 7 && run.length <= 10)
}

// The runs of digits a list holds that are too short or too long to be a
// student ID: a typo, or a number that is not an ID (a year, a phone number).
// Said under the list, as they would otherwise be skipped without a word.
function notStudentIdsIn(text) {
  return [...new Set((String(text ?? '').match(/\d+/g) ?? []).filter((run) => run.length >= 5 && (run.length < 7 || run.length > 10)))]
}

export function useYearEndModule() {
  const loading = ref(false)
  const loadError = ref('')
  const students = ref([])
  const lastClosed = ref(null)

  // Ticked = the proposal goes ahead for that student. Everything with a
  // proposal starts ticked; the admin unticks the exceptions.
  const selectedIds = ref(new Set())
  const search = ref('')

  const registrarListText = ref('')
  const registrarListSkipped = computed(() => notStudentIdsIn(registrarListText.value))
  const registrarListReport = ref(null)

  const confirmOpen = ref(false)
  const applying = ref(false)
  const applyError = ref('')
  const result = ref(null)

  const proposable = computed(() => students.value.filter((student) => student.proposed))
  const finishing = computed(() => students.value.filter((student) => student.proposed === 'graduate'))

  const searchTerm = computed(() => search.value.trim().toLowerCase())

  function matchesSearch(student) {
    if (!searchTerm.value) return true

    return [student.name, student.student_id, student.email]
      .some((field) => String(field ?? '').toLowerCase().includes(searchTerm.value))
  }

  function buildGroup(key, title, description, outcome, rows, selectable = true) {
    const visible = rows.filter(matchesSearch)

    return {
      key,
      title,
      description,
      outcome,
      selectable,
      total: rows.length,
      selected: rows.filter((student) => selectedIds.value.has(student.id)).length,
      visible,
      allVisibleSelected: visible.length > 0 && visible.every((student) => selectedIds.value.has(student.id)),
    }
  }

  // Finishing students first, since they are the ones to check against the
  // registrar's list, then the years below them from the top down.
  const groups = computed(() => {
    const built = []

    if (finishing.value.length > 0) {
      built.push(buildGroup(
        'graduate',
        'Finishing students',
        'Fourth year and above. Ticked students are marked Graduated and leave the class lists; unticked students stay enrolled at the same year level.',
        'Graduate',
        finishing.value,
      ))
    }

    const advancingLevels = [...new Set(
      students.value.filter((student) => student.proposed === 'advance').map((student) => student.year_level),
    )].sort((a, b) => b - a)

    advancingLevels.forEach((level) => {
      built.push(buildGroup(
        `advance-${level}`,
        yearLevelLabel(level),
        `Ticked students move up to ${yearLevelLabel(level + 1)}; unticked students repeat ${yearLevelLabel(level)}.`,
        `Move up to ${yearLevelLabel(level + 1)}`,
        students.value.filter((student) => student.proposed === 'advance' && student.year_level === level),
      ))
    })

    const unplaced = students.value.filter((student) => !student.proposed)
    if (unplaced.length > 0) {
      built.push(buildGroup(
        'none',
        'No year level on record',
        'There is nothing to move these students up from. Give them a year level on the Users page first if they should be included.',
        'No change',
        unplaced,
        false,
      ))
    }

    return built
  })

  const graduateCount = computed(() => finishing.value.filter((student) => selectedIds.value.has(student.id)).length)
  const advanceCount = computed(() => proposable.value
    .filter((student) => student.proposed === 'advance' && selectedIds.value.has(student.id)).length)
  const stayCount = computed(() => students.value.length - graduateCount.value - advanceCount.value)
  const changeCount = computed(() => graduateCount.value + advanceCount.value)

  const decisions = computed(() => proposable.value
    .filter((student) => selectedIds.value.has(student.id))
    .map((student) => ({ id: student.id, action: student.proposed, year_level: student.year_level })))

  async function loadProposal() {
    loading.value = true
    loadError.value = ''

    try {
      const { data } = await yearEndApi.proposal()

      students.value = Array.isArray(data?.students) ? data.students : []
      lastClosed.value = data?.last_closed ?? null
      selectedIds.value = new Set(students.value.filter((student) => student.proposed).map((student) => student.id))
      registrarListReport.value = null
    } catch (error) {
      students.value = []
      loadError.value = firstApiError(error, 'Unable to load the end-of-year list.')
    } finally {
      loading.value = false
    }
  }

  function toggleStudent(studentId) {
    const next = new Set(selectedIds.value)

    if (next.has(studentId)) next.delete(studentId)
    else next.add(studentId)

    selectedIds.value = next
  }

  // Acts on the rows currently shown, so "untick all" under a search only
  // touches what the search found.
  function setGroupSelection(group, shouldSelect) {
    const next = new Set(selectedIds.value)

    group.visible.forEach((student) => {
      if (shouldSelect) next.add(student.id)
      else next.delete(student.id)
    })

    selectedIds.value = next
  }

  /*
   * The registrar's list of graduates decides the finishing group outright:
   * on the list means graduating, not on it means staying. IDs on the list
   * that are not finishing students here are reported, not acted on -- a
   * typo, or someone still filed in a lower year, is for a person to settle.
   */
  function applyRegistrarList() {
    const listedIds = new Set(studentIdsIn(registrarListText.value))

    if (listedIds.size === 0) {
      registrarListReport.value = { empty: true }
      return
    }

    const next = new Set(selectedIds.value)
    const finishingIds = new Set()
    let graduating = 0

    finishing.value.forEach((student) => {
      const studentId = String(student.student_id ?? '')
      finishingIds.add(studentId)

      if (listedIds.has(studentId)) {
        next.add(student.id)
        graduating += 1
      } else {
        next.delete(student.id)
      }
    })

    const byStudentId = new Map(students.value.map((student) => [String(student.student_id ?? ''), student]))
    const unmatched = [...listedIds]
      .filter((studentId) => !finishingIds.has(studentId))
      .map((studentId) => {
        const student = byStudentId.get(studentId)

        return {
          student_id: studentId,
          note: student
            ? `${student.name} is in ${yearLevelLabel(student.year_level)}, not a finishing student`
            : 'not found among current students',
        }
      })

    selectedIds.value = next
    registrarListReport.value = {
      empty: false,
      listed: listedIds.size,
      graduating,
      staying: finishing.value.length - graduating,
      unmatched,
    }
  }

  async function readRegistrarListFile(file) {
    if (!file) return

    registrarListText.value = await file.text()
    applyRegistrarList()
  }

  function clearRegistrarList() {
    registrarListText.value = ''
    registrarListReport.value = null
  }

  function openConfirm() {
    if (changeCount.value === 0) return

    applyError.value = ''
    confirmOpen.value = true
  }

  function closeConfirm() {
    if (applying.value) return
    confirmOpen.value = false
  }

  async function confirmYearEnd() {
    if (applying.value || decisions.value.length === 0) return

    applying.value = true
    applyError.value = ''

    try {
      const { data } = await yearEndApi.apply(decisions.value)

      result.value = {
        message: data?.message ?? 'School year closed.',
        graduated: Number(data?.graduated ?? 0),
        advanced: Number(data?.advanced ?? 0),
        skipped: Array.isArray(data?.skipped) ? data.skipped : [],
      }
      confirmOpen.value = false
      search.value = ''
      clearRegistrarList()
    } catch (error) {
      applyError.value = firstApiError(error, 'Unable to close the school year. Nothing was changed.')
    } finally {
      applying.value = false
    }
  }

  onMounted(loadProposal)

  return {
    loading,
    loadError,
    students,
    lastClosed,
    selectedIds,
    search,
    groups,
    graduateCount,
    advanceCount,
    stayCount,
    changeCount,
    registrarListText,
    registrarListReport,
    registrarListSkipped,
    confirmOpen,
    applying,
    applyError,
    result,
    loadProposal,
    toggleStudent,
    setGroupSelection,
    applyRegistrarList,
    readRegistrarListFile,
    clearRegistrarList,
    openConfirm,
    closeConfirm,
    confirmYearEnd,
  }
}
