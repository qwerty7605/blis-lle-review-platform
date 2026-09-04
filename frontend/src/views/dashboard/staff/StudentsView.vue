<template>
  <section class="room-view">
    <div v-if="directoryError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ directoryError }}</span>
    </div>

    <article class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <UserRound :size="18" />
          <h3>Students</h3>
        </div>
        <button type="button" class="ghost-btn" :disabled="directoryLoading" @click="loadStudentsDirectory">
          <RefreshCw :size="14" :class="{ 'spin-soft': directoryLoading }" />
          Refresh
        </button>
      </header>

      <div class="user-directory-summary">
        <article
          v-for="card in studentDirectoryCards"
          :key="card.key"
          class="user-directory-summary-card"
          :class="`is-${card.tone}`"
        >
          <span>{{ card.label }}</span>
          <strong>{{ card.value }}</strong>
          <small>{{ card.note }}</small>
        </article>
      </div>

      <div class="management-toolbar">
        <input
          v-model.trim="studentFilters.search"
          type="text"
          class="text-input"
          placeholder="Search name, student ID, email, room, or exam"
        />
        <select v-model="studentFilters.year_level" class="text-input narrow">
          <option value="">All year levels</option>
          <option v-for="option in yearLevelOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <select v-model="studentFilters.archive_state" class="text-input narrow">
          <option value="current">Current records</option>
          <option value="archived">Archived records</option>
          <option value="all">All records</option>
        </select>
      </div>

      <div v-if="directoryLoading && filteredStudents.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading students</h4>
        <p>Preparing the year-level directory and performance summary.</p>
      </div>

      <div v-else-if="filteredStudents.length === 0" class="room-empty-state">
        <UserRound :size="42" />
        <h4>No students found</h4>
        <p>Adjust the filters to show current or archived student records.</p>
      </div>

      <template v-else>
        <div class="directory-table-wrap">
          <table class="directory-table">
            <thead>
              <tr>
                <th class="directory-table-name-cell" :aria-sort="sortAriaValue('name')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('name')">
                    Student
                    <span class="directory-sort-arrow">{{ sortIndicator('name') }}</span>
                  </button>
                </th>
                <th :aria-sort="sortAriaValue('student_id')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('student_id')">
                    ID
                    <span class="directory-sort-arrow">{{ sortIndicator('student_id') }}</span>
                  </button>
                </th>
                <th :aria-sort="sortAriaValue('year_level')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('year_level')">
                    Year
                    <span class="directory-sort-arrow">{{ sortIndicator('year_level') }}</span>
                  </button>
                </th>
                <th :aria-sort="sortAriaValue('room_count')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('room_count')">
                    Sections
                    <span class="directory-sort-arrow">{{ sortIndicator('room_count') }}</span>
                  </button>
                </th>
                <th class="numeric" :aria-sort="sortAriaValue('attempts_submitted')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('attempts_submitted')">
                    Submitted
                    <span class="directory-sort-arrow">{{ sortIndicator('attempts_submitted') }}</span>
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
                <th :aria-sort="sortAriaValue('last_activity_at')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('last_activity_at')">
                    Activity
                    <span class="directory-sort-arrow">{{ sortIndicator('last_activity_at') }}</span>
                  </button>
                </th>
                <th :aria-sort="sortAriaValue('email')">
                  <button type="button" class="directory-sort-btn" @click="toggleStudentSort('email')">
                    Email
                    <span class="directory-sort-arrow">{{ sortIndicator('email') }}</span>
                  </button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="student in pagedStudents"
                :key="student.id"
                :class="{ 'is-archived': isArchivedStudent(student) }"
              >
                <td class="directory-table-name-cell">
                  <strong>{{ student.name }}</strong>
                  <span v-if="isArchivedStudent(student)" class="pill danger">
                    Archived {{ archivedDateLabel(student) }}
                  </span>
                </td>
                <td>{{ student.student_id || '—' }}</td>
                <td>
                  <span class="pill navy">{{ yearLevelShortLabel(student.year_level) }}</span>
                </td>
                <td :title="roomNamesLabel(student)">
                  <span class="directory-table-sections">{{ roomNamesLabel(student) }}</span>
                  <small class="muted">{{ student.room_count }} section{{ student.room_count === 1 ? '' : 's' }}</small>
                </td>
                <td class="numeric">{{ student.attempts_submitted }}</td>
                <td class="numeric">
                  <span class="pill" :class="scoreTone(student)">
                    {{ formatPercent(student.average_score_percent) }}
                  </span>
                </td>
                <td class="numeric">{{ formatPercent(student.pass_rate_percent) }}</td>
                <td>
                  <strong>{{ formatPercent(student.latest_score_percent) }}</strong>
                  <small v-if="latestExamLabel(student)" class="muted">{{ latestExamLabel(student) }}</small>
                </td>
                <td>
                  <span>{{ lastActivityLabel(student) }}</span>
                  <small v-if="strongestSubjectLabel(student)" class="muted">
                    Best in {{ strongestSubjectLabel(student) }}
                  </small>
                </td>
                <td class="directory-table-email-cell">{{ student.email }}</td>
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
  </section>
</template>

<script setup>
import { AlertCircle, RefreshCw, UserRound } from 'lucide-vue-next'
import { useStudentsModule } from '../composables/useStudentsModule'

const {
  directoryLoading,
  directoryError,
  studentFilters,
  yearLevelOptions,
  filteredStudents,
  pagedStudents,
  studentsPagination,
  studentsRangeLabel,
  canGoToPreviousStudentsPage,
  canGoToNextStudentsPage,
  goToStudentsPage,
  toggleStudentSort,
  sortIndicator,
  sortAriaValue,
  studentDirectoryCards,
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
</script>

<style scoped src="../dashboard.css"></style>
