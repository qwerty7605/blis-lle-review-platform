<template>
  <section ref="pageRef" class="room-view">
    <div v-if="resultsError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ resultsError }}</span>
    </div>

    <article class="surface-card room-shell-card is-flush">
      <header class="room-page-head">
        <div class="room-page-title">
          <Award :size="18" />
          <h3>Record a licensure result</h3>
        </div>
      </header>

      <p class="muted lle-intro">
        Board results are published by the PRC, so they are transcribed here once a batch has
        sat the exam. Each recorded outcome pairs with that graduate's fourth-year subject scores
        to form one training row for the Exam Outcome model.
      </p>

      <!-- One at a time, for a late result or a correction; or a whole batch
           from the published list, without looking each student up. -->
      <div class="lle-mode" role="tablist" aria-label="How to record">
        <button type="button" role="tab" class="lle-mode-tab" :class="{ active: recordMode === 'batch' }" :aria-selected="(recordMode === 'batch').toString()" @click="recordMode = 'batch'">
          <Users :size="14" /> By Batch
        </button>
        <button type="button" role="tab" class="lle-mode-tab" :class="{ active: recordMode === 'single' }" :aria-selected="(recordMode === 'single').toString()" @click="recordMode = 'single'">
          <UserRound :size="14" /> One Student
        </button>
      </div>

      <LicensureBatchEntry v-if="recordMode === 'batch'" @saved="refreshAfterBatch" />

      <template v-else>
      <div class="lle-form">
        <div class="lle-field lle-field-wide">
          <label for="lle-student">Student</label>

          <div v-if="selectedStudent" class="lle-selected">
            <div>
              <strong>{{ selectedStudent.name }}</strong>
              <small class="muted">{{ selectedStudent.student_id || 'No student ID' }} · {{ selectedStudent.email }}</small>
            </div>
            <button type="button" class="ghost-btn" @click="clearSelectedStudent">Change</button>
          </div>

          <template v-else>
            <input
              id="lle-student"
              v-model="studentQuery"
              type="text"
              class="text-input"
              placeholder="Search by name, student ID, or email"
              autocomplete="off"
            />
            <p v-if="studentLookupLoading" class="muted lle-hint">Searching…</p>
            <ul v-else-if="studentMatches.length > 0" class="lle-matches">
              <li v-for="student in studentMatches" :key="student.id">
                <button type="button" @click="selectStudent(student)">
                  <strong>{{ student.name }}</strong>
                  <small class="muted">
                    {{ student.student_id || 'No student ID' }}
                    <span v-if="student.archived_at"> · archived</span>
                  </small>
                </button>
              </li>
            </ul>
            <p v-else-if="studentQuery.trim().length >= 2" class="muted lle-hint">No students matched.</p>
          </template>
        </div>

        <div class="lle-field">
          <label for="lle-year">Exam year</label>
          <input id="lle-year" v-model="form.exam_year" type="number" class="text-input" min="1900" max="2999" />
        </div>

        <div class="lle-field">
          <label for="lle-date">Date taken</label>
          <input id="lle-date" v-model="form.taken_at" type="date" class="text-input" />
        </div>

        <div class="lle-field">
          <label for="lle-result">Result</label>
          <select id="lle-result" v-model="form.result" class="text-input">
            <option value="pass">Passed</option>
            <option value="fail">Failed</option>
          </select>
        </div>

        <div class="lle-field">
          <label for="lle-rating">General average</label>
          <input
            id="lle-rating"
            v-model="form.rating"
            type="number"
            class="text-input"
            step="0.01"
            min="0"
            max="100"
            placeholder="Optional"
          />
        </div>

        <div class="lle-field lle-field-wide">
          <label for="lle-remarks">Remarks</label>
          <input id="lle-remarks" v-model="form.remarks" type="text" class="text-input" placeholder="Optional" />
        </div>
      </div>

      <div v-if="saveError" class="feedback danger">
        <AlertCircle :size="15" />
        <span>{{ saveError }}</span>
      </div>
      <div v-else-if="saveNotice" class="feedback success">
        <CheckCircle2 :size="15" />
        <span>{{ saveNotice }}</span>
      </div>

      <div class="management-toolbar">
        <p class="muted">
          Recording the same student and exam year again corrects that record rather than adding a second one.
        </p>
        <div class="management-actions">
          <button type="button" class="primary-btn" :disabled="!canSubmit" @click="submitResult">
            <Save :size="14" />
            {{ saving ? 'Saving…' : 'Record result' }}
          </button>
        </div>
      </div>
      </template>
    </article>

    <article class="surface-card room-shell-card is-flush">
      <header class="room-page-head is-inline">
        <div class="room-page-title">
          <BrainCircuit :size="18" />
          <h3>Exam Outcome model</h3>
        </div>
        <button
          v-if="canManageModel"
          type="button"
          class="primary-btn"
          :disabled="modelBusy || modelLoading || !modelStatus?.can_retrain"
          :title="modelStatus?.retrain_blocked_reason || ''"
          @click="retrainModel"
        >
          <RefreshCw :size="14" :class="{ 'spin-soft': modelBusy }" />
          {{ modelBusy ? 'Retraining…' : 'Retrain model' }}
        </button>
      </header>

      <div v-if="modelLoading && !modelStatus" class="room-empty-state">
        <RefreshCw :size="18" class="spin-soft" />
        <p>Loading the Exam Outcome model…</p>
      </div>

      <template v-else-if="modelStatus">
        <p class="muted lle-intro">
          {{ modelInUse }} It is retrained only when an administrator asks, on the original records plus
          every graduate with a confirmed board result, and a new version is used only if its accuracy is
          at least the baseline and its Kappa at least that of the version in use.
        </p>

        <dl class="lle-model-facts">
          <div>
            <dt>Original records</dt>
            <dd>
              {{ modelStatus.base_dataset.present ? modelStatus.data.base_rows : 'Missing' }}
              <small class="muted">{{ modelStatus.base_dataset.path }}</small>
            </dd>
          </div>
          <div>
            <dt>Graduates with a confirmed result</dt>
            <dd>
              {{ modelStatus.data.confirmed_graduates }}
              <small v-if="modelStatus.data.skipped_graduates > 0" class="muted">
                {{ modelStatus.data.skipped_graduates }} more left out: no complete fourth-year semester
              </small>
            </dd>
          </div>
          <div>
            <dt>New since the last training</dt>
            <dd>
              {{ modelStatus.data.new_since_last_training }}
              <small class="muted">{{ modelStatus.data.minimum_new_results }} needed to retrain</small>
            </dd>
          </div>
        </dl>

        <p v-if="modelStatus.retrain_blocked_reason" class="muted lle-intro">
          {{ modelStatus.retrain_blocked_reason }}
        </p>

        <div class="directory-table-wrap lle-versions-wrap">
          <table class="directory-table lle-versions">
            <caption class="visually-hidden">Every version of the Exam Outcome model and the figures it was judged on</caption>
            <thead>
              <tr>
                <th scope="col">Version</th>
                <th scope="col">Trained</th>
                <th scope="col" class="numeric">Rows</th>
                <th scope="col" class="numeric">Failed rows</th>
                <th scope="col" class="numeric">Accuracy</th>
                <th scope="col" class="numeric">Kappa</th>
                <th scope="col" class="numeric">Failed recall</th>
                <th scope="col" class="numeric">Baseline</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="version in modelStatus.versions" :key="version.version">
                <td>
                  <strong>v{{ version.version }}</strong>
                  <small class="muted lle-version-label">{{ version.label }}</small>
                  <code class="lle-version-file">{{ version.file_path }}</code>
                </td>
                <td>{{ version.trained_at ? new Date(version.trained_at).toLocaleDateString() : 'Shipped with the system' }}</td>
                <td class="numeric">{{ version.rows ?? '—' }}</td>
                <td class="numeric">{{ version.failed ?? '—' }}</td>
                <td class="numeric">{{ percent(version.accuracy) }}</td>
                <td class="numeric">{{ version.kappa === null ? '—' : Number(version.kappa).toFixed(4) }}</td>
                <td class="numeric">{{ percent(version.failed_recall) }}</td>
                <td class="numeric">{{ percent(version.baseline) }}</td>
                <td>
                  <span v-if="version.active" class="pill success">In use</span>
                  <button
                    v-else-if="canManageModel && version.can_activate"
                    type="button"
                    class="ghost-btn"
                    :disabled="modelBusy"
                    @click="activateModelVersion(version.version)"
                  >
                    <Undo2 :size="14" />
                    Use this version
                  </button>
                  <span v-else class="pill neutral">{{ version.status === 'rejected' ? 'Not taken up' : 'Not in use' }}</span>
                  <small v-if="version.note" class="muted lle-version-note">{{ version.note }}</small>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="muted lle-intro">
          Accuracy, Kappa and Failed recall are from 10-fold cross-validation with random seed 1. Baseline is the
          share of rows that passed, which is what always answering "passed" would score.
        </p>
      </template>

      <div v-if="modelError" class="feedback danger" role="alert">
        <AlertCircle :size="15" />
        <span>{{ modelError }}</span>
      </div>
      <div v-else-if="modelNotice" class="feedback success" role="status">
        <CheckCircle2 :size="15" />
        <span>{{ modelNotice }}</span>
      </div>

    </article>

    <article class="surface-card room-shell-card is-flush">
      <header class="room-page-head is-inline">
        <div class="room-page-title">
          <ClipboardList :size="18" />
          <h3>Recorded results</h3>
        </div>
        <button type="button" class="ghost-btn" :disabled="resultsLoading" @click="loadResults">
          <RefreshCw :size="14" :class="{ 'spin-soft': resultsLoading }" />
          Refresh
        </button>
      </header>

      <div class="management-toolbar">
        <input
          v-model="filters.search"
          type="text"
          class="text-input"
          inputmode="search"
          enterkeyhint="search"
          placeholder="Search name, student ID, or email"
          aria-label="Search recorded results"
        />
        <div class="management-actions lle-filter-pair">
          <input
            v-model="filters.exam_year"
            type="number"
            inputmode="numeric"
            class="text-input narrow"
            placeholder="Year"
            aria-label="Exam year"
          />
          <select v-model="filters.result" class="text-input narrow" aria-label="Outcome">
            <option value="">All outcomes</option>
            <option value="pass">Passed</option>
            <option value="fail">Failed</option>
          </select>
        </div>
      </div>

      <div v-if="resultsLoading && results.length === 0" class="room-empty-state">
        <RefreshCw :size="18" class="spin-soft" />
        <p>Loading recorded results…</p>
      </div>

      <div v-else-if="results.length === 0" class="room-empty-state">
        <Award :size="18" />
        <p>No licensure results recorded yet.</p>
      </div>

      <template v-else>
        <!-- Where the table cannot fit: one row per result; Remove sits
             inside the opened row, so a thumb scrolling past can't delete a
             result by accident. -->
        <ul v-if="useRows" class="directory-row-list is-in-card">
          <li v-for="result in results" :key="result.id">
            <details class="directory-row-details">
              <summary class="directory-row">
                <span class="directory-row-copy">
                  <strong>{{ result.student?.name ?? 'Unknown student' }}</strong>
                  <span>{{ resultRowMeta(result) }}</span>
                </span>
                <span class="pill" :class="result.result === 'pass' ? 'success' : 'danger'">
                  {{ result.result === 'pass' ? 'Passed' : 'Failed' }}
                </span>
                <ChevronDown :size="18" class="directory-row-more directory-row-chevron" aria-hidden="true" />
              </summary>

              <dl class="directory-row-facts">
                <div>
                  <dt>Date taken</dt>
                  <dd>{{ formatTakenAt(result.taken_at) }}</dd>
                </div>
                <div>
                  <dt>Rating</dt>
                  <dd>{{ formatRating(result.rating) }}</dd>
                </div>
                <div>
                  <dt>Recorded by</dt>
                  <dd>{{ result.recorder?.name ?? '—' }}</dd>
                </div>
                <div v-if="result.remarks" class="is-wide">
                  <dt>Remarks</dt>
                  <dd>{{ result.remarks }}</dd>
                </div>
              </dl>

              <div class="directory-row-actions">
                <button type="button" class="ghost-btn" @click="removeResult(result)">
                  <Trash2 :size="15" />
                  Remove result
                </button>
              </div>
            </details>
          </li>
        </ul>

        <div v-else class="directory-table-wrap">
          <table class="directory-table lle-table">
            <thead>
              <tr>
                <th class="directory-table-name-cell">Student</th>
                <th>Student ID</th>
                <th>Exam year</th>
                <th>Date taken</th>
                <th>Result</th>
                <th class="numeric">Rating</th>
                <th>Recorded by</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="result in results" :key="result.id">
                <td class="directory-table-name-cell">
                  <strong>{{ result.student?.name ?? 'Unknown student' }}</strong>
                  <small v-if="result.remarks" class="muted">{{ result.remarks }}</small>
                </td>
                <td>{{ result.student?.student_id || '—' }}</td>
                <td>{{ result.exam_year }}</td>
                <td>{{ formatTakenAt(result.taken_at) }}</td>
                <td>
                  <span class="pill" :class="result.result === 'pass' ? 'success' : 'danger'">
                    {{ result.result === 'pass' ? 'Passed' : 'Failed' }}
                  </span>
                </td>
                <td class="numeric">{{ formatRating(result.rating) }}</td>
                <td class="muted">{{ result.recorder?.name ?? '—' }}</td>
                <td>
                  <button type="button" class="ghost-btn" @click="removeResult(result)">Remove</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="management-toolbar users-pagination">
          <p class="muted">{{ rangeLabel }}</p>
          <div class="management-actions">
            <button
              type="button"
              class="ghost-btn"
              :disabled="!canGoToPreviousPage"
              @click="goToPage(meta.current_page - 1)"
            >
              Previous
            </button>
            <span class="pill neutral">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button
              type="button"
              class="ghost-btn"
              :disabled="!canGoToNextPage"
              @click="goToPage(meta.current_page + 1)"
            >
              Next
            </button>
          </div>
        </div>
      </template>
    </article>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import {
  AlertCircle,
  Award,
  BrainCircuit,
  CheckCircle2,
  ChevronDown,
  ClipboardList,
  RefreshCw,
  Save,
  Trash2,
  Undo2,
  UserRound,
  Users,
} from 'lucide-vue-next'
import { useLleResultsModule } from '../composables/useLleResultsModule'
import LicensureBatchEntry from './LicensureBatchEntry.vue'
import { estimatePageWidth, useElementWidth, usePhoneLayout } from '../composables/useViewport'

const isPhone = usePhoneLayout()

// The table's eight columns need about 880px, plus the card around them.
// With less than that the list is rows: on a 1024px laptop with the sidebar
// open the page has 708px, and Remove, the last column, was off its edge.
const LLE_TABLE_MIN_WIDTH = 940

const pageRef = ref(null)
const pageWidth = useElementWidth(pageRef, estimatePageWidth())
const useRows = computed(() => (
  pageWidth.value > 0 ? pageWidth.value < LLE_TABLE_MIN_WIDTH : isPhone.value
))

function formatTakenAt(value) {
  if (!value) return '—'
  return new Date(value).toLocaleDateString()
}

function formatRating(value) {
  if (value === null || value === undefined || value === '') return '—'
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric.toFixed(2) : '—'
}

function resultRowMeta(result) {
  return [result.student?.student_id, result.exam_year ? `${result.exam_year} exam` : null]
    .filter(Boolean)
    .join(' · ')
}

const {
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
  modelStatus,
  modelLoading,
  modelBusy,
  modelError,
  modelNotice,
  canManageModel,
  retrainModel,
  activateModelVersion,
  loadModelStatus,
} = useLleResultsModule()

// A batch is how results arrive, so that is the way the page opens.
const recordMode = ref('batch')

// The list of recorded results and the model's count of what it can learn
// from both changed.
function refreshAfterBatch() {
  loadResults()
  loadModelStatus({ silent: true })
}

const modelInUse = computed(() => {
  const live = modelStatus.value?.live
  if (!live) return ''

  if (live.source === 'built_in') {
    return `Exam Outcome results are using version ${live.version}, the original model, trained on ${live.trained_on} past review-exam records.`
  }

  return `Exam Outcome results are using version ${live.version}, retrained on ${live.trained_on} rows.`
})

function percent(value) {
  return value === null || value === undefined ? '—' : `${Number(value).toFixed(2)}%`
}
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.lle-mode {
  display: inline-flex;
  gap: 4px;
  margin: 0 18px 14px;
  padding: 4px;
  border-radius: 999px;
  background: #eef1f8;
  border: 1px solid rgba(13, 21, 71, 0.08);
  justify-self: start;
  width: fit-content;
  max-width: calc(100% - 36px);
}

.lle-mode-tab {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 34px;
  padding: 0 14px;
  border: 0;
  border-radius: 999px;
  background: transparent;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-soft);
  white-space: nowrap;
  cursor: pointer;
}

.lle-mode-tab.active {
  background: var(--panel-navy);
  color: #ffffff;
}

@media (max-width: 640px) {
  .lle-mode {
    display: flex;
    width: auto;
    justify-self: stretch;
    margin-inline: 14px;
    max-width: none;
  }

  .lle-mode-tab {
    flex: 1 1 0;
  }
}

@media (pointer: coarse) {
  .lle-mode-tab {
    min-height: 40px;
  }
}

.lle-intro {
  margin: 0 18px 14px;
  max-width: 70ch;
  line-height: 1.5;
}

.lle-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 14px;
  margin: 0 18px 14px;
}

.lle-field {
  display: grid;
  gap: 6px;
  align-content: start;
}

.lle-field-wide {
  grid-column: 1 / -1;
}

.lle-field label {
  font-size: 12px;
  font-weight: 700;
  color: var(--lnu-text-muted);
}

.lle-hint {
  margin: 0;
  font-size: 12px;
}

.lle-selected {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 14px;
  background: #ffffff;
}

.lle-selected small {
  display: block;
  font-size: 12px;
}

.lle-matches {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 14px;
  overflow: hidden;
  background: #ffffff;
}

.lle-matches button {
  width: 100%;
  border: 0;
  background: transparent;
  padding: 9px 12px;
  text-align: left;
  font: inherit;
  cursor: pointer;
}

.lle-matches button:hover {
  background: rgba(26, 35, 126, 0.07);
}

.lle-matches small {
  display: block;
  font-size: 12px;
}

.lle-model-facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin: 0 18px 14px;
}

.lle-model-facts > div {
  padding: 12px 14px;
  border: 1px solid rgba(13, 21, 71, 0.1);
  border-radius: var(--radius-sm);
  background: #ffffff;
}

.lle-model-facts dt {
  font-size: 12px;
  font-weight: 700;
  color: var(--lnu-text-muted);
}

.lle-model-facts dd {
  margin: 4px 0 0;
  font-size: 20px;
  font-weight: 700;
}

.lle-model-facts small {
  display: block;
  font-size: 12px;
  font-weight: 400;
  line-height: 1.4;
}

.lle-versions-wrap {
  margin: 0 18px 14px;
}

/* Sized by its own columns, like the results table below. */
.lle-versions {
  min-width: 0;
}

/* The shared header cell has no padding of its own: elsewhere a sort button
   fills it. These headings are plain text, set like those buttons. */
.lle-versions thead th {
  padding: 9px 12px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.lle-versions th.numeric {
  text-align: right;
}

.lle-versions td {
  vertical-align: top;
}

.lle-version-label,
.lle-version-note {
  display: block;
  font-size: 12px;
  line-height: 1.4;
}

.lle-version-note {
  margin-top: 4px;
  max-width: 34ch;
  white-space: normal;
}

.lle-version-file {
  display: block;
  margin-top: 2px;
  font-size: 11.5px;
  color: var(--lnu-text-muted);
}

/* Sized by its eight columns. The shared table rule holds every table to
   1080px, which this one never needed: on a 1366px laptop that pushed its
   last column, Remove, 82px off the right edge. */
.lle-table {
  min-width: 0;
}

/* A found student is a row to tap, and Change is a button beside a name. */
@media (pointer: coarse) {
  .lle-matches button {
    min-height: 48px;
  }
}
</style>
