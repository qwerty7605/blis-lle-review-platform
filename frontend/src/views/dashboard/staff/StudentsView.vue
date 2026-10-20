<template>
  <section ref="pageRef" class="room-view">
    <div v-if="directoryError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ directoryError }}</span>
    </div>

    <article class="surface-card room-shell-card is-flush">
      <header class="room-page-head is-page-title">
        <div class="room-page-title">
          <UserRound :size="18" />
          <h3>Students</h3>
        </div>
        <Teleport to="#layout-topbar-actions" defer :disabled="!isCompact">
          <button
            type="button"
            :class="isCompact ? 'appbar-icon-action' : 'ghost-btn'"
            :aria-label="isCompact ? 'Refresh students' : undefined"
            :disabled="directoryLoading"
            @click="loadStudentsDirectory"
          >
            <RefreshCw :size="isCompact ? 20 : 14" :class="{ 'spin-soft': directoryLoading }" />
            <template v-if="!isCompact">Refresh</template>
          </button>
        </Teleport>
      </header>

      <div class="user-directory-summary">
        <article
          v-for="card in studentDirectoryCards"
          :key="card.key"
          class="user-directory-summary-card"
          :class="`is-${card.tone}`"
        >
          <span>{{ card.label }}</span>
          <strong>{{ typeof card.value === 'number' ? card.value.toLocaleString() : card.value }}</strong>
          <small>{{ card.note }}</small>
        </article>
      </div>

      <div class="management-toolbar" :class="{ 'is-search-row': useRows }">
        <input
          v-model.trim="studentFilters.search"
          type="text"
          class="text-input"
          inputmode="search"
          enterkeyhint="search"
          :placeholder="useRows ? 'Search students' : 'Search name, student ID, email, room, or exam'"
          aria-label="Search students"
        />
        <button
          v-if="useRows"
          type="button"
          class="ghost-btn filter-trigger"
          aria-haspopup="dialog"
          @click="filtersOpen = true"
        >
          <SlidersHorizontal :size="16" />
          Filters
          <span v-if="activeStudentFilters.length > 0" class="filter-count">
            {{ activeStudentFilters.length }}
            <span class="visually-hidden">active</span>
          </span>
        </button>
        <template v-else>
          <select v-model="studentFilters.year_level" class="text-input narrow" aria-label="Year level">
            <option value="">All year levels</option>
            <option v-for="option in yearLevelOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
          <select v-model="studentFilters.student_status" class="text-input narrow" aria-label="Status">
            <option value="">All statuses</option>
            <option v-for="option in studentStatusOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
          <input
            v-model="studentFilters.admission_year"
            type="number"
            class="text-input narrow"
            min="1900"
            max="2999"
            placeholder="Batch year"
            aria-label="Batch year"
          />
          <select v-model="studentFilters.archive_state" class="text-input narrow" aria-label="Records">
            <option v-for="option in archiveStateOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </template>
      </div>

      <div v-if="useRows && activeStudentFilters.length > 0" v-scroll-hint class="filter-chip-row">
        <button
          v-for="chip in activeStudentFilters"
          :key="chip.key"
          type="button"
          class="filter-chip"
          :aria-label="`Remove filter: ${chip.label}`"
          @click="clearStudentFilter(chip.key)"
        >
          {{ chip.label }}
          <X :size="14" aria-hidden="true" />
        </button>
      </div>

      <div v-if="directoryLoading && students.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading students</h4>
        <p>Preparing the year-level directory and performance summary.</p>
      </div>

      <div v-else-if="students.length === 0" class="room-empty-state">
        <UserRound :size="42" />
        <h4>No students found</h4>
        <p>Adjust the filters to show current or archived student records.</p>
      </div>

      <template v-else>
        <!-- Where the table cannot fit: on a phone its 12 columns were a
             sideways scroll five screens wide. Each student is a row that
             opens to the full record. -->
        <ul v-if="useRows" class="directory-row-list is-in-card">
          <li v-for="student in students" :key="student.id">
            <details class="directory-row-details" :class="{ 'is-archived': isArchivedStudent(student) }">
              <summary class="directory-row">
                <span class="directory-row-copy">
                  <strong>{{ student.name }}</strong>
                  <span>{{ studentRowMeta(student) }}</span>
                </span>
                <span class="pill" :class="scoreTone(student)">{{ studentScoreLabel(student) }}</span>
                <ChevronDown :size="18" class="directory-row-more directory-row-chevron" aria-hidden="true" />
              </summary>

              <dl class="directory-row-facts">
                <div class="is-wide">
                  <dt>Email</dt>
                  <dd>{{ student.email }}</dd>
                </div>
                <div>
                  <dt>Batch</dt>
                  <dd>{{ student.admission_year || '--' }}</dd>
                </div>
                <div class="is-wide">
                  <dt>Sections</dt>
                  <dd>{{ roomNamesLabel(student) }}</dd>
                </div>
                <div>
                  <dt>Submitted</dt>
                  <dd>{{ student.attempts_submitted }}</dd>
                </div>
                <div>
                  <dt>Pass rate</dt>
                  <dd>{{ formatPercent(student.pass_rate_percent) }}</dd>
                </div>
                <div>
                  <dt>Latest</dt>
                  <dd>
                    {{ formatPercent(student.latest_score_percent) }}
                    <small v-if="latestExamLabel(student)" class="muted">{{ latestExamLabel(student) }}</small>
                  </dd>
                </div>
                <div>
                  <dt>Activity</dt>
                  <dd>{{ lastActivityLabel(student) }}</dd>
                </div>
                <div v-if="strongestSubjectLabel(student)">
                  <dt>Best in</dt>
                  <dd>{{ strongestSubjectLabel(student) }}</dd>
                </div>
                <div v-if="isArchivedStudent(student)">
                  <dt>Archived</dt>
                  <dd>{{ archivedDateLabel(student) }}</dd>
                </div>
              </dl>

              <RouterLink
                class="primary-btn directory-row-profile-btn"
                :to="{ name: 'dashboard-student-profile', params: { studentId: student.id } }"
              >
                View profile
                <ArrowRight :size="15" />
              </RouterLink>
            </details>
          </li>
        </ul>

        <div v-else class="directory-table-wrap">
          <table class="directory-table students-table" :class="{ 'is-merged': isMergedTable, 'is-tight': isTightTable }">
            <thead>
              <tr>
                <!-- In the eight-column table a heading carries two sorts, one
                     for each line of its cells: the folded columns kept
                     theirs. -->
                <th class="directory-table-name-cell" :aria-sort="headingAriaSort('name', 'email')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('name')">
                    Student
                    <span class="directory-sort-arrow">{{ sortIndicator('name') }}</span>
                  </button>
                  <button v-if="isMergedTable" type="button" class="directory-sort-btn is-second" @click="toggleStudentSort('email')">
                    Email
                    <span class="directory-sort-arrow">{{ sortIndicator('email') }}</span>
                  </button>
                </th>
                <th :aria-sort="headingAriaSort('student_id', 'admission_year')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('student_id')">
                    ID
                    <span class="directory-sort-arrow">{{ sortIndicator('student_id') }}</span>
                  </button>
                  <button v-if="isMergedTable" type="button" class="directory-sort-btn is-second" @click="toggleStudentSort('admission_year')">
                    Batch
                    <span class="directory-sort-arrow">{{ sortIndicator('admission_year') }}</span>
                  </button>
                </th>
                <th :aria-sort="headingAriaSort('year_level', 'student_status')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('year_level')">
                    Year
                    <span class="directory-sort-arrow">{{ sortIndicator('year_level') }}</span>
                  </button>
                  <button v-if="isMergedTable" type="button" class="directory-sort-btn is-second" @click="toggleStudentSort('student_status')">
                    Status
                    <span class="directory-sort-arrow">{{ sortIndicator('student_status') }}</span>
                  </button>
                </th>
                <th v-if="!isMergedTable" :aria-sort="sortAriaValue('admission_year')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('admission_year')">
                    Batch
                    <span class="directory-sort-arrow">{{ sortIndicator('admission_year') }}</span>
                  </button>
                </th>
                <th v-if="!isMergedTable" :aria-sort="sortAriaValue('student_status')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('student_status')">
                    Status
                    <span class="directory-sort-arrow">{{ sortIndicator('student_status') }}</span>
                  </button>
                </th>
                <th :aria-sort="sortAriaValue('room_count')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('room_count')">
                    Sections
                    <span class="directory-sort-arrow">{{ sortIndicator('room_count') }}</span>
                  </button>
                </th>
                <th class="numeric" :aria-sort="headingAriaSort('attempts_submitted', 'last_activity_at')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('attempts_submitted')">
                    Submitted
                    <span class="directory-sort-arrow">{{ sortIndicator('attempts_submitted') }}</span>
                  </button>
                  <button v-if="isMergedTable" type="button" class="directory-sort-btn is-second" @click="toggleStudentSort('last_activity_at')">
                    Last active
                    <span class="directory-sort-arrow">{{ sortIndicator('last_activity_at') }}</span>
                  </button>
                </th>
                <th class="numeric" :aria-sort="sortAriaValue('average_score_percent')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('average_score_percent')">
                    Avg Score
                    <span class="directory-sort-arrow">{{ sortIndicator('average_score_percent') }}</span>
                  </button>
                </th>
                <th class="numeric" :aria-sort="sortAriaValue('pass_rate_percent')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('pass_rate_percent')">
                    Pass Rate
                    <span class="directory-sort-arrow">{{ sortIndicator('pass_rate_percent') }}</span>
                  </button>
                </th>
                <th :aria-sort="sortAriaValue('latest_score_percent')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('latest_score_percent')">
                    Latest
                    <span class="directory-sort-arrow">{{ sortIndicator('latest_score_percent') }}</span>
                  </button>
                </th>
                <th v-if="!isMergedTable" :aria-sort="sortAriaValue('last_activity_at')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('last_activity_at')">
                    Activity
                    <span class="directory-sort-arrow">{{ sortIndicator('last_activity_at') }}</span>
                  </button>
                </th>
                <th v-if="!isMergedTable" :aria-sort="sortAriaValue('email')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('email')">
                    Email
                    <span class="directory-sort-arrow">{{ sortIndicator('email') }}</span>
                  </button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="student in students"
                :key="student.id"
                :class="{ 'is-archived': isArchivedStudent(student) }"
              >
                <td class="directory-table-name-cell">
                  <RouterLink
                    class="directory-profile-link"
                    :to="{ name: 'dashboard-student-profile', params: { studentId: student.id } }"
                  >
                    <strong>{{ student.name }}</strong>
                  </RouterLink>
                  <span v-if="isArchivedStudent(student)" class="pill danger">
                    Archived {{ archivedDateLabel(student) }}
                  </span>
                  <small v-if="isMergedTable" class="muted students-cell-line" :title="student.email">{{ student.email }}</small>
                </td>
                <td>
                  {{ student.student_id || '—' }}
                  <small v-if="isMergedTable && student.admission_year" class="muted">Batch {{ student.admission_year }}</small>
                </td>
                <td>
                  <span class="pill navy">{{ yearLevelShortLabel(student.year_level) }}</span>
                  <small v-if="isMergedTable" class="muted">{{ studentStatusLabel(student.student_status) }}</small>
                </td>
                <td v-if="!isMergedTable">{{ student.admission_year || '--' }}</td>
                <td v-if="!isMergedTable">
                  <span
                    class="pill"
                    :class="student.student_status === 'enrolled' ? 'success' : 'neutral'"
                  >{{ studentStatusLabel(student.student_status) }}</span>
                </td>
                <td :title="roomNamesLabel(student)">
                  <span class="directory-table-sections">{{ roomNamesLabel(student) }}</span>
                  <small class="muted">{{ student.room_count }} section{{ student.room_count === 1 ? '' : 's' }}</small>
                </td>
                <td class="numeric">
                  {{ student.attempts_submitted }}
                  <small v-if="isMergedTable" class="muted" :title="`Last activity: ${lastActivityLabel(student)}`">
                    {{ lastActivityDateLabel(student) }}
                  </small>
                </td>
                <td class="numeric">
                  <span class="pill" :class="scoreTone(student)">
                    {{ formatPercent(student.average_score_percent) }}
                  </span>
                </td>
                <td class="numeric">{{ formatPercent(student.pass_rate_percent) }}</td>
                <td>
                  <strong>{{ formatPercent(student.latest_score_percent) }}</strong>
                  <small v-if="latestExamLabel(student)" class="muted students-cell-line" :title="latestExamLabel(student)">
                    {{ latestExamLabel(student) }}
                  </small>
                </td>
                <td v-if="!isMergedTable">
                  <span>{{ lastActivityLabel(student) }}</span>
                  <small
                    v-if="strongestSubjectLabel(student)"
                    class="muted students-cell-line"
                    :title="`Best in ${strongestSubjectLabel(student)}`"
                  >
                    Best in {{ strongestSubjectLabel(student) }}
                  </small>
                </td>
                <td v-if="!isMergedTable" class="directory-table-email-cell" :title="student.email">{{ student.email }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="management-toolbar users-pagination">
          <p class="muted">{{ studentsRangeLabel }}</p>
          <div class="management-actions">
            <button
              type="button"
              class="ghost-btn"
              :disabled="!canGoToPreviousStudentsPage"
              @click="goToStudentsPage(studentsPagination.current_page - 1)"
            >
              Previous
            </button>
            <span class="pill neutral">
              Page {{ studentsPagination.current_page }} of {{ studentsPagination.last_page }}
            </span>
            <button
              type="button"
              class="ghost-btn"
              :disabled="!canGoToNextStudentsPage"
              @click="goToStudentsPage(studentsPagination.current_page + 1)"
            >
              Next
            </button>
          </div>
        </div>
      </template>
    </article>

    <BottomSheet v-if="useRows" v-model:open="filtersOpen" title="Filter and sort">
      <label class="field-stack">
        <span class="field-label">Sort by</span>
        <select v-model="studentSortChoice" class="text-input">
          <option v-if="!isListedSortChoice" :value="studentSortChoice">Sorted by column</option>
          <option v-for="option in sortChoices" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <label class="field-stack">
        <span class="field-label">Year level</span>
        <select v-model="studentFilters.year_level" class="text-input">
          <option value="">All year levels</option>
          <option v-for="option in yearLevelOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <label class="field-stack">
        <span class="field-label">Status</span>
        <select v-model="studentFilters.student_status" class="text-input">
          <option value="">All statuses</option>
          <option v-for="option in studentStatusOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <label class="field-stack">
        <span class="field-label">Batch year</span>
        <input
          v-model="studentFilters.admission_year"
          type="number"
          inputmode="numeric"
          class="text-input"
          min="1900"
          max="2999"
          placeholder="Any batch"
        />
      </label>
      <label class="field-stack">
        <span class="field-label">Records</span>
        <select v-model="studentFilters.archive_state" class="text-input">
          <option v-for="option in archiveStateOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>

      <template #footer>
        <button
          type="button"
          class="ghost-btn"
          :disabled="activeStudentFilters.length === 0"
          @click="resetStudentFilters"
        >
          Reset
        </button>
        <button type="button" class="primary-btn" @click="filtersOpen = false">
          {{ filterSheetDoneLabel }}
        </button>
      </template>
    </BottomSheet>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { AlertCircle, ArrowRight, ChevronDown, RefreshCw, SlidersHorizontal, UserRound, X } from 'lucide-vue-next'
import BottomSheet from '@/components/BottomSheet.vue'
import { formatCompactDate } from '../composables/useDashboardFormatters'
import { useStudentsModule } from '../composables/useStudentsModule'
import { vScrollHint } from '@/utils/scrollHint'
import { estimatePageWidth, useCompactLayout, useElementWidth, usePhoneLayout } from '../composables/useViewport'

const isCompact = useCompactLayout()
const isPhone = usePhoneLayout()

/*
 * Three layouts, chosen by the room the page really has (the sidebar takes
 * 264px of the window, or 84px collapsed):
 *
 *   under 920px     rows that open, as on a phone
 *   920 to 1599px   the table with four columns folded into the others:
 *                   email under the name, batch under the ID, status under
 *                   the year, last activity under the submitted count
 *                   (under 1010px with its long cells cut shorter)
 *   1600px and up   all twelve columns
 *
 * Twelve columns need about 1,600px. On a 1366px laptop the page has
 * 1,050px, and the scores, the columns this page is opened for, were the
 * ones off its right edge.
 */
const STUDENT_ROWS_BELOW = 920
const STUDENT_TIGHT_TABLE_BELOW = 1010
const STUDENT_FULL_TABLE_FROM = 1600

const pageRef = ref(null)
const pageWidth = useElementWidth(pageRef, estimatePageWidth())

const useRows = computed(() => (
  pageWidth.value > 0 ? pageWidth.value < STUDENT_ROWS_BELOW : isPhone.value
))
const isMergedTable = computed(() => (
  !useRows.value && pageWidth.value > 0 && pageWidth.value < STUDENT_FULL_TABLE_FROM
))
const isTightTable = computed(() => (
  isMergedTable.value && pageWidth.value < STUDENT_TIGHT_TABLE_BELOW
))

const {
  directoryLoading,
  directoryError,
  studentFilters,
  yearLevelOptions,
  studentStatusOptions,
  studentStatusLabel,
  students,
  studentSort,
  studentsPagination,
  studentsRangeLabel,
  canGoToPreviousStudentsPage,
  canGoToNextStudentsPage,
  goToStudentsPage,
  toggleStudentSort,
  sortIndicator,
  sortAriaValue,
  studentDirectoryCards,
  yearLevelLabel,
  yearLevelShortLabel,
  formatPercent,
  scoreTone,
  isArchivedStudent,
  roomNamesLabel,
  latestExamLabel,
  lastActivityLabel,
  strongestSubjectLabel,
  archivedDateLabel,
  loadStudentsDirectory,
} = useStudentsModule()

const STUDENT_FILTER_DEFAULTS = Object.freeze({
  year_level: '',
  student_status: '',
  admission_year: '',
  archive_state: 'current',
})

const archiveStateOptions = [
  { value: 'current', label: 'Current records' },
  { value: 'archived', label: 'Archived records' },
  { value: 'all', label: 'All records' },
]

const filtersOpen = ref(false)

// Filters moved off their defaults, shown as removable chips on phones.
const activeStudentFilters = computed(() => {
  const chips = []

  if (studentFilters.year_level) {
    chips.push({
      key: 'year_level',
      label: yearLevelOptions.find((option) => option.value === studentFilters.year_level)?.label ?? studentFilters.year_level,
    })
  }

  if (studentFilters.student_status) {
    chips.push({ key: 'student_status', label: studentStatusLabel(studentFilters.student_status) })
  }

  if (studentFilters.admission_year !== '' && studentFilters.admission_year !== null) {
    chips.push({ key: 'admission_year', label: `Batch ${studentFilters.admission_year}` })
  }

  if (studentFilters.archive_state !== STUDENT_FILTER_DEFAULTS.archive_state) {
    chips.push({
      key: 'archive_state',
      label: archiveStateOptions.find((option) => option.value === studentFilters.archive_state)?.label ?? studentFilters.archive_state,
    })
  }

  return chips
})

function clearStudentFilter(key) {
  if (!(key in STUDENT_FILTER_DEFAULTS)) return
  studentFilters[key] = STUDENT_FILTER_DEFAULTS[key]
}

function resetStudentFilters() {
  Object.assign(studentFilters, STUDENT_FILTER_DEFAULTS)
}

// The table sorts from its column headers; phones have no headers, so the
// same sorts are offered as one list, direction included.
const sortChoices = [
  { value: 'name:asc', label: 'Name (A–Z)' },
  { value: 'name:desc', label: 'Name (Z–A)' },
  { value: 'student_id:asc', label: 'Student ID' },
  { value: 'year_level:asc', label: 'Year level' },
  { value: 'admission_year:desc', label: 'Newest batch' },
  { value: 'student_status:asc', label: 'Status' },
  { value: 'average_score_percent:desc', label: 'Highest average score' },
  { value: 'average_score_percent:asc', label: 'Lowest average score' },
  { value: 'pass_rate_percent:desc', label: 'Highest pass rate' },
  { value: 'pass_rate_percent:asc', label: 'Lowest pass rate' },
  { value: 'latest_score_percent:desc', label: 'Highest latest score' },
  { value: 'attempts_submitted:desc', label: 'Most exams submitted' },
  { value: 'room_count:desc', label: 'Most sections' },
  { value: 'last_activity_at:desc', label: 'Most recent activity' },
  { value: 'email:asc', label: 'Email' },
]

const studentSortChoice = computed({
  get: () => `${studentSort.key}:${studentSort.direction}`,
  set: (value) => {
    const [key, direction] = String(value).split(':')
    if (!key || !['asc', 'desc'].includes(direction)) return

    studentSort.key = key
    studentSort.direction = direction
    goToStudentsPage(1)
  },
})

// What a heading reports to a screen reader: its own column's sort, or in
// the eight-column table that of the column folded into it.
function headingAriaSort(key, foldedKey) {
  const own = sortAriaValue(key)
  if (own !== 'none' || !isMergedTable.value) return own

  return sortAriaValue(foldedKey)
}

// A sort chosen from a column heading may be one the list does not name
// (student ID, Z to A), and a select with no matching option shows blank.
const isListedSortChoice = computed(() => (
  sortChoices.some((option) => option.value === studentSortChoice.value)
))

const filterSheetDoneLabel = computed(() => {
  if (directoryLoading.value) return 'Updating…'

  const total = Number(studentsPagination.value.total ?? 0)
  if (total < 1) return 'No matching students'

  return `Show ${total.toLocaleString()} student${total === 1 ? '' : 's'}`
})

function studentRowMeta(student) {
  return [
    student.student_id,
    Number(student.year_level) >= 1 ? yearLevelLabel(student.year_level) : null,
    studentStatusLabel(student.student_status),
  ].filter(Boolean).join(' · ')
}

// The date alone, for the line under the submitted count: the full
// timestamp is wider than the column it sits in.
function lastActivityDateLabel(student) {
  return student?.last_activity_at ? formatCompactDate(student.last_activity_at) : 'No activity'
}

function studentScoreLabel(student) {
  if (Number(student?.attempts_submitted ?? 0) < 1) return 'No attempts'
  return `Avg ${formatPercent(student.average_score_percent)}`
}

watch(useRows, () => {
  filtersOpen.value = false
})
</script>

<style scoped>
/* A student's name opens their profile. */
.directory-profile-link {
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
  text-decoration: none;
}

.directory-profile-link:hover strong,
.directory-profile-link:focus-visible strong {
  text-decoration: underline;
}

.directory-profile-link:focus-visible {
  outline: 2px solid var(--dk-line-lnu-navy, var(--lnu-navy));
  outline-offset: 2px;
  border-radius: 4px;
}

/* A second line in a cell that may be as long as an exam's title or an
   email address: one line, cut with an ellipsis, whole in its tooltip.
   Left to run on, two of these set the table's width by themselves. */
.students-cell-line {
  max-width: 170px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.students-table .directory-table-email-cell {
  max-width: 200px;
}

/* The second sort in a heading, under the first as the second line is
   under the first in the cells below. flex, not inline-flex: two inline
   buttons at full width sat side by side and doubled the column. */
.students-table.is-merged thead .directory-sort-btn {
  display: flex;
}

/* The first gives up its bottom padding to bring the second up under it;
   pulled up with a margin instead, the second lay over the first. */
.students-table.is-merged thead .directory-sort-btn:not(.is-second):not(:only-child) {
  padding-bottom: 1px;
}

.directory-sort-btn.is-second {
  padding-top: 0;
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.01em;
  text-transform: none;
  color: var(--lnu-text-muted);
}

/* Eight columns are sized by what is in them, not held to the 1080px the
   twelve needed. */
.students-table.is-merged {
  min-width: 0;
}

.students-table.is-merged .directory-table-sections {
  max-width: 150px;
}

.students-table.is-tight .directory-table-name-cell {
  min-width: 180px;
}

.students-table.is-tight .students-cell-line,
.students-table.is-tight .directory-table-sections {
  max-width: 120px;
}

.directory-row-profile-btn {
  margin: 4px 16px 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 44px;
  text-decoration: none;
}
</style>
