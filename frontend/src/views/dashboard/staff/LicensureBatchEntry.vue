<template>
  <div class="lleb">
    <div class="lleb-setup">
      <label class="field-stack">
        <span class="field-label">
          Batch
          <InfoTip text="A batch is the year its students were admitted. Choose one to list its students, then mark each as passed or failed from the published list. Leave a student blank if they did not take the exam." />
        </span>
        <select v-model="admissionYear" class="text-input" :disabled="batchesLoading">
          <option value="">{{ batchesLoading ? 'Loading batches...' : 'Choose a batch' }}</option>
          <option v-for="batch in batches" :key="batch.admission_year" :value="String(batch.admission_year)">
            Batch {{ batch.admission_year }} ({{ batch.students_count }} {{ batch.students_count === 1 ? 'student' : 'students' }})
          </option>
        </select>
      </label>

      <label class="field-stack">
        <span class="field-label">Exam Year</span>
        <input v-model="examYear" type="number" inputmode="numeric" class="text-input" min="1900" max="2999" />
      </label>

      <label class="field-stack">
        <span class="field-label">Date Taken <em>(optional)</em></span>
        <input v-model="takenAt" type="date" class="text-input" />
      </label>
    </div>

    <div v-if="loadError" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ loadError }}</span></div>


    <div v-else-if="rosterLoading" class="room-detail-loading">
      <RefreshCw :size="15" class="spin-soft" />
      <span>Loading the batch...</span>
    </div>

    <p v-else-if="rows.length === 0" class="muted lleb-hint">No students are on record for this batch.</p>

    <template v-else>
      <div class="lleb-tools">
        <label class="lleb-search">
          <Search :size="14" aria-hidden="true" />
          <input v-model.trim="search" type="search" placeholder="Find a student in this batch" aria-label="Find a student in this batch" />
        </label>

        <div class="lleb-tools-actions">
          <button type="button" class="ghost-btn" :disabled="saving" @click="markBlank('pass')">Mark the Rest as Passed</button>
          <button type="button" class="ghost-btn" :disabled="saving" @click="markBlank('fail')">Mark the Rest as Failed</button>
          <button type="button" class="ghost-btn" :disabled="saving || !hasChanges" @click="resetRows">Undo Changes</button>
        </div>
      </div>

      <p class="lleb-count" role="status">
        <strong>{{ counts.pass }}</strong> passed &middot; <strong>{{ counts.fail }}</strong> failed &middot;
        <strong>{{ counts.blank }}</strong> left blank
      </p>

      <ul class="lleb-list">
        <li v-for="row in visibleRows" :key="row.id" class="lleb-row" :class="{ 'is-changed': isChanged(row) }">
          <span class="lleb-row-copy">
            <strong>{{ row.name }}</strong>
            <span>
              {{ row.student_id || 'No student ID' }}
              <template v-if="row.recorded"> &middot; on record as {{ row.recorded === 'pass' ? 'Passed' : 'Failed' }}</template>
            </span>
          </span>

          <span class="lleb-choice" role="group" :aria-label="`Result for ${row.name}`">
            <button type="button" class="lleb-choice-btn is-pass" :class="{ active: row.result === 'pass' }" :aria-pressed="row.result === 'pass'" @click="setResult(row, 'pass')">Passed</button>
            <button type="button" class="lleb-choice-btn is-fail" :class="{ active: row.result === 'fail' }" :aria-pressed="row.result === 'fail'" @click="setResult(row, 'fail')">Failed</button>
          </span>

          <input
            v-model="row.rating"
            type="number"
            inputmode="decimal"
            class="text-input lleb-rating"
            step="0.01"
            min="0"
            max="100"
            placeholder="Average"
            :aria-label="`General average for ${row.name}, optional`"
            :disabled="!row.result"
          />
        </li>
      </ul>

      <p v-if="visibleRows.length === 0" class="muted lleb-hint">No student in this batch matches "{{ search }}".</p>
    </template>

    <div v-if="saveError" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ saveError }}</span></div>
    <div v-else-if="saveNotice" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ saveNotice }}</span></div>

    <div v-if="rows.length > 0" class="management-toolbar">
      <div class="management-actions">
        <InfoTip
          :text="`Only students marked passed or failed are saved. A student already on record for ${examYear || 'this year'} is corrected, not added twice.`"
          label="About saving results"
        />
        <button type="button" class="primary-btn" :disabled="!canSave" @click="save">
          <Save :size="14" />
          {{ saving ? 'Saving...' : `Record ${changedEntries.length} ${changedEntries.length === 1 ? 'Result' : 'Results'}` }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import InfoTip from '@/components/InfoTip.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { AlertCircle, CheckCircle2, RefreshCw, Save, Search } from 'lucide-vue-next'
import { useDashboardDataServices } from '../composables/useDashboardDataServices'

const emit = defineEmits(['saved'])
const services = useDashboardDataServices()

const batches = ref([])
const batchesLoading = ref(false)
const admissionYear = ref('')
const examYear = ref(new Date().getFullYear())
const takenAt = ref('')

const rows = ref([])
const rosterLoading = ref(false)
const loadError = ref('')
const search = ref('')
const saving = ref(false)
const saveError = ref('')
const saveNotice = ref('')

function firstApiError(error, fallback) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()

  return messages.length > 0 ? String(messages[0]) : (error?.response?.data?.message ?? fallback)
}

const ratingText = (value) => (value === null || value === undefined || value === '' ? '' : String(Number(value)))

// One row per student: what is on record for this exam year (`recorded`,
// `recordedRating`), and what the page now says (`result`, `rating`).
function toRow(student) {
  return {
    id: student.id,
    name: student.name,
    student_id: student.student_id,
    recorded: student.result ?? null,
    recordedRating: ratingText(student.rating),
    result: student.result ?? null,
    rating: ratingText(student.rating),
  }
}

let rosterToken = 0

async function loadRoster() {
  const token = ++rosterToken
  loadError.value = ''
  saveNotice.value = ''

  if (!admissionYear.value) {
    rows.value = []
    return
  }

  rosterLoading.value = true

  try {
    const { data } = await services.getLleBatch({
      admission_year: Number(admissionYear.value),
      ...(Number(examYear.value) >= 1900 ? { exam_year: Number(examYear.value) } : {}),
    })

    // A later choice was made while this was on its way.
    if (token !== rosterToken) return

    batches.value = data?.batches ?? batches.value
    rows.value = (data?.students ?? []).map(toRow)
  } catch (error) {
    if (token === rosterToken) loadError.value = firstApiError(error, 'Unable to load that batch.')
  } finally {
    if (token === rosterToken) rosterLoading.value = false
  }
}

async function loadBatches() {
  batchesLoading.value = true

  try {
    const { data } = await services.getLleBatch()
    batches.value = data?.batches ?? []
  } catch (error) {
    loadError.value = firstApiError(error, 'Unable to load the batches.')
  } finally {
    batchesLoading.value = false
  }
}

onMounted(loadBatches)

// The roster shows what is recorded for the exam year, so either choice
// changing means reading it again.
watch([admissionYear, examYear], () => {
  search.value = ''
  loadRoster()
})

const visibleRows = computed(() => {
  const needle = search.value.toLowerCase()
  if (!needle) return rows.value

  return rows.value.filter((row) => [row.name, row.student_id].some((value) => String(value ?? '').toLowerCase().includes(needle)))
})

const counts = computed(() => ({
  pass: rows.value.filter((row) => row.result === 'pass').length,
  fail: rows.value.filter((row) => row.result === 'fail').length,
  blank: rows.value.filter((row) => !row.result).length,
}))

// Pressing the chosen result again clears it: back to "did not take it".
// A result already on record cannot be cleared here, only corrected; removing
// one is done from the list of recorded results below.
function setResult(row, result) {
  if (row.result === result && !row.recorded) {
    row.result = null
    row.rating = ''
    return
  }

  row.result = result
}

function isChanged(row) {
  return Boolean(row.result) && (row.result !== row.recorded || ratingText(row.rating) !== row.recordedRating)
}

// What goes to the server: only rows that now say something new.
const changedEntries = computed(() => rows.value.filter(isChanged).map((row) => ({
  user_id: row.id,
  result: row.result,
  // An empty average means "not published", not zero.
  rating: row.rating === '' || row.rating === null ? null : Number(row.rating),
})))

const hasChanges = computed(() => rows.value.some((row) => row.result !== row.recorded || ratingText(row.rating) !== row.recordedRating))

function markBlank(result) {
  for (const row of rows.value) {
    if (!row.result) row.result = result
  }
}

function resetRows() {
  for (const row of rows.value) {
    row.result = row.recorded
    row.rating = row.recordedRating
  }
}

const canSave = computed(() => (
  !saving.value
  && changedEntries.value.length > 0
  && Number(examYear.value) >= 1900
  && Number(examYear.value) <= 2999
))

async function save() {
  if (!canSave.value) return

  const badRating = changedEntries.value.find((entry) => entry.rating !== null && !(entry.rating >= 0 && entry.rating <= 100))

  if (badRating) {
    saveError.value = 'A general average has to be a number from 0 to 100.'
    return
  }

  saving.value = true
  saveError.value = ''
  saveNotice.value = ''

  try {
    const { data } = await services.recordLleBatch({
      exam_year: Number(examYear.value),
      taken_at: takenAt.value || null,
      entries: changedEntries.value,
    })

    const parts = []
    if (data?.new) parts.push(`${data.new} new`)
    if (data?.corrected) parts.push(`${data.corrected} corrected`)

    await loadRoster()
    saveNotice.value = `Recorded results for ${data?.recorded ?? 0} ${data?.recorded === 1 ? 'student' : 'students'}`
      + `${parts.length > 0 ? ` (${parts.join(', ')})` : ''} for ${examYear.value}.`
    emit('saved')
  } catch (error) {
    saveError.value = firstApiError(error, 'Unable to record those results. Nothing was saved.')
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.lleb {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 14px;
  padding: 0 18px 4px;
}

.lleb-setup {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 12px;
}

.field-label em {
  font-style: normal;
  font-weight: 400;
  color: var(--ink-soft);
}

.lleb-hint {
  margin: 0;
  line-height: 1.5;
}

.lleb-tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.lleb-search {
  flex: 1 1 220px;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  height: var(--control-height, 40px);
  padding: 0 12px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: #ffffff;
  color: var(--ink-soft);
}

.lleb-search input {
  flex: 1 1 auto;
  min-width: 0;
  border: 0;
  outline: 0;
  background: transparent;
  font: inherit;
  font-size: 13.5px;
  color: var(--ink);
}

.lleb-tools-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.lleb-count {
  margin: 0;
  font-size: 13px;
  color: var(--ink-soft);
}

.lleb-count strong {
  color: var(--lnu-navy-deep);
}

.lleb-list {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  margin: 0;
  padding: 0;
  list-style: none;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  max-height: min(56vh, 520px);
  overflow-y: auto;
}

.lleb-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-left: 3px solid transparent;
}

.lleb-row + .lleb-row {
  border-top: 1px solid var(--border-soft);
}

/* Marked on this visit and not saved yet. */
.lleb-row.is-changed {
  border-left-color: var(--lnu-gold, #c9a227);
  background: rgba(201, 162, 39, 0.06);
}

.lleb-row-copy {
  flex: 1 1 0;
  min-width: 0;
  display: grid;
  gap: 2px;
}

.lleb-row-copy strong {
  font-size: 13.5px;
  color: var(--lnu-navy-deep);
  overflow-wrap: anywhere;
}

.lleb-row-copy span {
  font-size: 12.5px;
  color: var(--ink-soft);
  overflow-wrap: anywhere;
}

.lleb-choice {
  flex: none;
  display: inline-flex;
  padding: 3px;
  gap: 3px;
  border-radius: 999px;
  background: #eef1f8;
  border: 1px solid rgba(13, 21, 71, 0.08);
}

.lleb-choice-btn {
  min-height: 30px;
  padding: 0 12px;
  border: 0;
  border-radius: 999px;
  background: transparent;
  font: inherit;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink-soft);
  cursor: pointer;
}

/* The same pair the charts use for passing and below passing: indigo and
   amber, which stay apart for colour-blind readers where green and red do not. */
.lleb-choice-btn.is-pass.active {
  background: #3949ab;
  color: #ffffff;
}

.lleb-choice-btn.is-fail.active {
  background: #c98a1e;
  color: #ffffff;
}

.lleb-rating {
  flex: none;
  width: 104px;
}

@media (min-width: 720px) {
  .lleb-setup {
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr);
  }
}

@media (pointer: coarse) {
  .lleb-choice-btn {
    min-height: 38px;
    padding: 0 14px;
  }
}

/* Phones: the name takes the first line, the choice and the average the second. */
@media (max-width: 640px) {
  .lleb {
    padding: 0 14px 4px;
  }

  .lleb-row {
    flex-wrap: wrap;
  }

  .lleb-row-copy {
    flex-basis: 100%;
  }

  /* From nothing, so the average keeps its place on the same line. */
  .lleb-choice {
    flex: 1 1 0;
    min-width: 0;
  }

  .lleb-choice-btn {
    flex: 1 1 0;
  }

  .lleb-rating {
    width: 96px;
  }

  .lleb-tools-actions,
  .lleb-tools-actions .ghost-btn {
    width: 100%;
    justify-content: center;
  }

  .lleb-list {
    max-height: none;
  }
}
</style>
