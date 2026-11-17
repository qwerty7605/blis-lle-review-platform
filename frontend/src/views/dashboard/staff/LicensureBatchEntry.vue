<template>
  <div class="lleb">
    <div class="lleb-setup">
      <label class="field-stack">
        <span class="field-label">
          Batch
          <InfoTip text="A batch is the year its students were admitted. Choose one to list its students, then mark each from the published list: Passed, Failed, or Did Not Take for a student who did not sit the exam that year. A student left blank is not saved." />
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
        <input v-model="examYear" type="number" inputmode="numeric" class="text-input" min="1900" max="2999" v-bind="checks.bind('exam_year')" />
        <FieldError :checks="checks" field="exam_year" />
      </label>

      <label class="field-stack">
        <span class="field-label">Date Taken <em>(optional)</em></span>
        <input v-model="takenAt" type="date" class="text-input" v-bind="checks.bind('taken_at')" />
        <FieldError :checks="checks" field="taken_at" />
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

        <!-- Every student still blank at once. Did Not Take is the safe
             one for a list that only names the examinees: Failed for
             everyone left would record failures that never happened. -->
        <div class="lleb-tools-actions">
          <div class="lleb-mark-rest" role="group" aria-labelledby="lleb-mark-rest-label">
            <span id="lleb-mark-rest-label" class="lleb-mark-rest-label">Mark the rest as</span>
            <button type="button" class="ghost-btn" :disabled="saving" @click="markBlank('pass')">Passed</button>
            <button type="button" class="ghost-btn" :disabled="saving" @click="markBlank('fail')">Failed</button>
            <button type="button" class="ghost-btn" :disabled="saving" @click="markBlank(DID_NOT_TAKE)">Did Not Take</button>
          </div>
          <button type="button" class="ghost-btn" :disabled="saving || !hasChanges" @click="resetRows">Undo Changes</button>
        </div>
      </div>

      <p class="lleb-count" role="status">
        <strong>{{ counts.pass }}</strong> passed &middot; <strong>{{ counts.fail }}</strong> failed &middot;
        <strong>{{ counts.absent }}</strong> did not take &middot;
        <strong>{{ counts.blank }}</strong> left blank
        <template v-if="counts.locked > 0"> &middot; <strong>{{ counts.locked }}</strong> passed in an earlier year</template>
      </p>

      <ul class="lleb-list">
        <li
          v-for="row in visibleRows"
          :key="row.id"
          class="lleb-row"
          :class="{ 'is-changed': isChanged(row), 'is-flagged': rowFlags(row).length > 0, 'is-locked': isLocked(row), 'has-average': takesAverage(row) }"
        >
          <span class="lleb-row-copy">
            <strong>{{ row.name }}</strong>
            <span>
              {{ row.student_id || 'No student ID' }}
              <template v-if="row.recorded"> &middot; on record as {{ RESULT_LABELS[row.recorded] }}</template>
            </span>
            <!-- Nothing comes after a pass (LleResultRules), so these are
                 locked rather than listed blank for "Mark the Rest". -->
            <span v-if="isLocked(row)" class="lleb-row-note">
              Passed in {{ lockedBy(row).exam_year }}. Nothing can be recorded after a pass.
            </span>
            <span v-else-if="laterFor(row)" class="lleb-row-note">
              {{ laterFor(row).result === DID_NOT_TAKE ? 'On record as not taking it' : 'Has a result' }} in {{ laterFor(row).exam_year }}, so {{ examYear }} cannot be Passed.
            </span>
            <span v-for="reason in rowFlags(row)" :key="reason" class="lleb-row-flag">
              <AlertTriangle :size="13" aria-hidden="true" />
              {{ reason }}
            </span>
          </span>

          <span class="lleb-choice" role="group" :aria-label="`Result for ${row.name}`">
            <button
              type="button"
              class="lleb-choice-btn is-pass"
              :class="{ active: row.result === 'pass' }"
              :aria-pressed="row.result === 'pass'"
              :disabled="isLocked(row) || Boolean(laterFor(row))"
              @click="setResult(row, 'pass')"
            >Passed</button>
            <button
              type="button"
              class="lleb-choice-btn is-fail"
              :class="{ active: row.result === 'fail' }"
              :aria-pressed="row.result === 'fail'"
              :disabled="isLocked(row)"
              @click="setResult(row, 'fail')"
            >Failed</button>
            <button
              type="button"
              class="lleb-choice-btn is-absent"
              :class="{ active: row.result === DID_NOT_TAKE }"
              :aria-pressed="row.result === DID_NOT_TAKE"
              :disabled="isLocked(row)"
              @click="setResult(row, DID_NOT_TAKE)"
            >Did Not Take</button>
          </span>

          <input
            v-model="row.rating"
            type="number"
            inputmode="decimal"
            class="text-input lleb-rating"
            :class="{ 'is-invalid': Boolean(ratingProblemFor(row)) }"
            :aria-invalid="ratingProblemFor(row) ? 'true' : undefined"
            :data-check="ratingProblemFor(row) ? 'lle-batch.ratings' : undefined"
            step="0.01"
            min="0"
            max="100"
            placeholder="Average"
            :aria-label="`General average for ${row.name}, optional`"
            :disabled="!takesAverage(row)"
            @blur="row.ratingLeft = true"
          />
        </li>
      </ul>

      <p v-if="visibleRows.length === 0" class="muted lleb-hint">No student in this batch matches "{{ search }}".</p>
      <FieldError :checks="checks" field="ratings" />
    </template>

    <div v-if="saveError" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ saveError }}</span></div>
    <div v-else-if="saveNotice" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ saveNotice }}</span></div>

    <div v-if="rows.length > 0" class="management-toolbar">
      <div class="management-actions">
        <InfoTip
          :text="`Only students given a result (Passed, Failed or Did Not Take) are saved. A student already on record for ${examYear || 'this year'} is corrected, not added twice. Did Not Take is never counted as a sitting: not in the passing rate, and not as an outcome for the Estimated Outcome model.`"
          label="About saving results"
        />
        <button type="button" class="primary-btn" :disabled="!canSave" @click="save">
          <Save :size="14" />
          {{ saving ? 'Saving...' : `Record ${changedEntries.length} ${changedEntries.length === 1 ? 'Result' : 'Results'}` }}
        </button>
      </div>
    </div>

    <LicensureSaveCheck :open="confirmOpen" :items="flaggedItems" @back="confirmOpen = false" @confirm="confirmSave" />
  </div>
</template>

<script setup>
import FieldError from '@/components/FieldError.vue'
import InfoTip from '@/components/InfoTip.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { AlertCircle, AlertTriangle, CheckCircle2, RefreshCw, Save, Search } from 'lucide-vue-next'
import { useDashboardDataServices } from '../composables/useDashboardDataServices'
import { decimalProblem, examDateProblem, problemMessage, useFieldChecks, yearProblem } from '@/utils/fieldChecks'
import {
  belowPassingProblem,
  currentYear,
  DID_NOT_TAKE,
  earlierPass,
  examYearProblem,
  laterSitting,
  RESULT_LABELS,
  replacedReason,
  unusualReasons,
} from '@/utils/lleResultChecks'
import LicensureSaveCheck from './LicensureSaveCheck.vue'

const emit = defineEmits(['saved'])
const services = useDashboardDataServices()

const batches = ref([])
const batchesLoading = ref(false)
const admissionYear = ref('')
const examYear = ref(currentYear())
const takenAt = ref('')

const rows = ref([])
const rosterLoading = ref(false)
const loadError = ref('')
const search = ref('')
const saving = ref(false)
const saveError = ref('')
const saveNotice = ref('')
// LleResultRules::PASSING_AVERAGES, sent with the roster.
const passingAverages = ref({})
// Save was pressed: every average's problem shows, not only those whose
// field was left.
const saveTried = ref(false)
// The "Check before saving" dialog (LicensureSaveCheck.vue).
const confirmOpen = ref(false)

function firstApiError(error, fallback) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()

  return messages.length > 0 ? String(messages[0]) : (error?.response?.data?.message ?? fallback)
}

const ratingText = (value) => (value === null || value === undefined || value === '' ? '' : String(Number(value)))

// One row per student: what is on record for this exam year (`recorded`,
// `recordedRating`), and what the page now says (`result`, `rating`).
// `others` is their results for other years, which this one must agree
// with; `status` whether they are marked graduated.
function toRow(student) {
  return {
    id: student.id,
    name: student.name,
    student_id: student.student_id,
    status: student.student_status ?? null,
    others: Array.isArray(student.other_results) ? student.other_results : [],
    recorded: student.result ?? null,
    recordedRating: ratingText(student.rating),
    result: student.result ?? null,
    rating: ratingText(student.rating),
    ratingLeft: false,
  }
}

let rosterToken = 0

async function loadRoster() {
  const token = ++rosterToken
  loadError.value = ''
  saveNotice.value = ''
  saveTried.value = false

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
    if (data?.passing_averages) passingAverages.value = data.passing_averages
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
    if (data?.passing_averages) passingAverages.value = data.passing_averages
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

// Nothing comes after a pass (LleResultRules): a student who passed in an
// earlier year is locked, and one with a result in a later year cannot pass
// this one.
const lockedBy = (row) => earlierPass(row.others, examYear.value)
const laterFor = (row) => laterSitting(row.others, examYear.value)
const isLocked = (row) => Boolean(lockedBy(row))
// Only a sitting has a general average.
const takesAverage = (row) => Boolean(row.result) && row.result !== DID_NOT_TAKE && !isLocked(row)

const counts = computed(() => ({
  pass: rows.value.filter((row) => row.result === 'pass').length,
  fail: rows.value.filter((row) => row.result === 'fail').length,
  absent: rows.value.filter((row) => row.result === DID_NOT_TAKE).length,
  blank: rows.value.filter((row) => !row.result && !isLocked(row)).length,
  locked: rows.value.filter((row) => !row.result && isLocked(row)).length,
}))

// Pressing the chosen result again clears it: back to blank, not saved.
// A result already on record cannot be cleared here, only corrected; removing
// one is done from the list of recorded results below.
function setResult(row, result) {
  if (isLocked(row) || (result === 'pass' && laterFor(row))) return

  if (row.result === result && !row.recorded) {
    row.result = null
    row.rating = ''
    return
  }

  row.result = result
  if (result === DID_NOT_TAKE) row.rating = ''
}

function isChanged(row) {
  return Boolean(row.result) && (row.result !== row.recorded || ratingText(row.rating) !== row.recordedRating)
}

// What goes to the server: only rows that now say something new.
const changedEntries = computed(() => rows.value.filter(isChanged).map((row) => ({
  user_id: row.id,
  result: row.result,
  // An empty average means "not published", not zero.
  rating: row.result === DID_NOT_TAKE || row.rating === '' || row.rating === null ? null : Number(row.rating),
})))

const hasChanges = computed(() => rows.value.some((row) => row.result !== row.recorded || ratingText(row.rating) !== row.recordedRating))

// Skips the rows that result cannot be given to, as their buttons do.
function markBlank(result) {
  for (const row of rows.value) {
    if (row.result || isLocked(row) || (result === 'pass' && laterFor(row))) continue

    row.result = result
  }
}

function resetRows() {
  for (const row of rows.value) {
    row.result = row.recorded
    row.rating = row.recordedRating
  }
}

const canSave = computed(() => !saving.value && changedEntries.value.length > 0)

// LleResultController::storeBatch and LleResultRules. A rating is checked
// only on a student being given a result: the field is off for the rest.
// A number that is not an average shows at once; a pass under the passing
// average once its field is left (7 on the way to 78 is not one) or Save
// is pressed.
function ratingProblem(row) {
  if (!takesAverage(row)) return { message: '', early: false }

  const format = problemMessage(decimalProblem(row.rating, { label: 'General average', min: 0, max: 100 }))
  if (format) return { message: format, early: true }

  return {
    message: belowPassingProblem({ result: row.result, rating: row.rating, examYear: examYear.value, passingAverages: passingAverages.value }),
    early: false,
  }
}

function ratingProblemFor(row) {
  const { message, early } = ratingProblem(row)

  return message && (early || row.ratingLeft || saveTried.value) ? message : ''
}

// Can be right, but usually a slip: marked on the row as it is filled in,
// and listed in one dialog when Save is pressed. Only rows that will be sent.
function rowFlags(row) {
  if (!isChanged(row)) return []

  const replaced = replacedReason({
    examYear: examYear.value,
    recordedResult: row.recorded,
    recordedRating: row.recordedRating,
    result: row.result,
    rating: row.rating,
  })

  return [
    ...(replaced ? [replaced] : []),
    ...unusualReasons({
      result: row.result,
      rating: row.rating,
      examYear: examYear.value,
      passingAverages: passingAverages.value,
      studentStatus: row.status,
    }),
  ]
}

const flaggedItems = computed(() => rows.value
  .map((row) => ({ id: row.id, name: row.name, reasons: rowFlags(row) }))
  .filter((item) => item.reasons.length > 0))

const checks = useFieldChecks('lle-batch', {
  exam_year: () => yearProblem(examYear.value, { label: 'Exam year', required: true }) || examYearProblem(examYear.value),
  taken_at: () => examDateProblem(takenAt.value, examYear.value),
  ratings: () => {
    const withProblem = rows.value.filter((row) => ratingProblem(row).message)
    if (withProblem.length === 0) return ''

    // The first one already showing on its row, so the line under the list
    // never names a student whose field does not show it yet.
    const showing = withProblem.find((row) => ratingProblemFor(row))
    const row = showing ?? withProblem[0]

    return { message: `${row.name}: ${ratingProblem(row).message}`, early: Boolean(showing) }
  },
})

async function save() {
  if (!canSave.value) return

  saveTried.value = true
  if (!checks.check()) return

  if (flaggedItems.value.length > 0) {
    confirmOpen.value = true
    return
  }

  await record()
}

async function confirmSave() {
  confirmOpen.value = false
  await record()
}

async function record() {
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
  background: var(--dk-bg-ffffff, #ffffff);
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
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.lleb-mark-rest {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.lleb-mark-rest-label {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink-soft);
  white-space: nowrap;
}

.lleb-count {
  margin: 0;
  font-size: 13px;
  color: var(--ink-soft);
}

.lleb-count strong {
  color: var(--dk-fg-lnu-navy-deep, var(--lnu-navy-deep));
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
  border-left-color: var(--dk-line-lnu-gold, var(--lnu-gold, var(--dk-line-c9a227, #c9a227)));
  background: var(--dk-bg-c9a227-a06, rgba(201, 162, 39, 0.06));
}

.lleb-row-copy {
  flex: 1 1 0;
  min-width: 0;
  display: grid;
  gap: 2px;
}

.lleb-row-copy strong {
  font-size: 13.5px;
  color: var(--dk-fg-lnu-navy-deep, var(--lnu-navy-deep));
  overflow-wrap: anywhere;
}

.lleb-row-copy span {
  font-size: 12.5px;
  color: var(--ink-soft);
  overflow-wrap: anywhere;
}

/* To be checked before saving (the dialog lists the same). After
   .is-changed, which a flagged row always is too. */
.lleb-row.is-flagged {
  border-left-color: var(--dk-line-c98a1e-a55, rgba(201, 138, 30, 0.55));
  background: var(--dk-bg-c98a1e-a07, rgba(201, 138, 30, 0.07));
}

.lleb-row-copy .lleb-row-flag {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  color: var(--ink);
  line-height: 1.4;
}

.lleb-row-flag svg {
  flex: none;
  margin-top: 2px;
  color: var(--dk-fg-9a6a0c, #9a6a0c);
}

.lleb-row-copy .lleb-row-note {
  line-height: 1.4;
}

.lleb-choice-btn:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}

.lleb-choice {
  flex: none;
  display: inline-flex;
  padding: 3px;
  gap: 3px;
  border-radius: 999px;
  background: var(--dk-bg-eef1f8, #eef1f8);
  border: 1px solid var(--dk-line-0d1547-a08, rgba(13, 21, 71, 0.08));
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
  background: var(--dk-bg-3949ab, #3949ab);
  color: var(--dk-fg-ffffff, #ffffff);
}

.lleb-choice-btn.is-fail.active {
  background: var(--dk-bg-c98a1e, #c98a1e);
  color: #ffffff;
}

/* Neither: a slate grey, apart from both by lightness as well as hue. */
.lleb-choice-btn.is-absent.active {
  background: var(--dk-bg-4b5567, #4b5567);
  color: var(--dk-fg-ffffff, #ffffff);
}

/* min-width: the shared .text-input holds every field to 180px, which on a
   phone squeezed the Passed/Failed pair until "Failed" was cut off. */
.lleb-rating {
  flex: none;
  width: 104px;
  min-width: 0;
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

  /* Three choices take the second line; the average, which only a pass or
     a fail has, a third one once either is chosen. */
  .lleb-choice {
    flex: 1 1 100%;
    min-width: 0;
  }

  .lleb-choice-btn {
    flex: 1 1 0;
    padding: 0 6px;
  }

  .lleb-rating {
    display: none;
    width: 100%;
  }

  .lleb-row.has-average .lleb-rating {
    display: block;
  }

  .lleb-tools-actions,
  .lleb-mark-rest {
    width: 100%;
  }

  .lleb-mark-rest .ghost-btn {
    flex: 1 1 0;
    justify-content: center;
    padding-inline: 6px;
  }

  .lleb-mark-rest-label {
    flex-basis: 100%;
  }

  .lleb-tools-actions > .ghost-btn {
    width: 100%;
    justify-content: center;
  }

  .lleb-list {
    max-height: none;
  }
}
</style>
