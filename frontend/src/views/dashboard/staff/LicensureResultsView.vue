<template>
  <section class="room-view">
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
        sat the exam. Each recorded outcome pairs with that student's mock-exam history to
        form one training example for the LET readiness predictor.
      </p>

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
        <!-- Phones: one row per result; Remove sits inside the opened row, so
             a thumb scrolling past can't delete a result by accident. -->
        <ul v-if="isPhone" class="directory-row-list is-in-card">
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
          <table class="directory-table">
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
import { AlertCircle, Award, CheckCircle2, ChevronDown, ClipboardList, RefreshCw, Save, Trash2 } from 'lucide-vue-next'
import { useLleResultsModule } from '../composables/useLleResultsModule'
import { usePhoneLayout } from '../composables/useViewport'

const isPhone = usePhoneLayout()

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
} = useLleResultsModule()
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
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
</style>
