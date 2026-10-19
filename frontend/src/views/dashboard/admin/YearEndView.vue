<template>
  <section class="room-view year-end-view">
    <RouterLink :to="{ name: 'dashboard-users' }" class="year-end-back">
      <ArrowLeft :size="15" />
      Back to Users
    </RouterLink>

    <div v-if="loadError" class="feedback danger" role="alert">
      <AlertCircle :size="15" />
      <span>{{ loadError }}</span>
    </div>

    <article class="surface-card room-shell-card is-flush">
      <header class="room-page-head is-page-title">
        <div class="room-page-title">
          <GraduationCap :size="18" />
          <h3>End of School Year</h3>
        </div>
      </header>

      <div v-if="loading && students.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading students</h4>
        <p>Working out who would graduate and who would move up.</p>
      </div>

      <!-- Done: what happened, and what was left alone and why. -->
      <div v-else-if="result" class="year-end-body">
        <div class="feedback success">
          <CheckCircle2 :size="15" />
          <span>{{ result.message }}</span>
        </div>

        <div class="user-directory-summary year-end-summary">
          <article class="user-directory-summary-card is-gold">
            <span>Graduated</span>
            <strong>{{ formatCount(result.graduated) }}</strong>
          </article>
          <article class="user-directory-summary-card is-success">
            <span>Moved up a year</span>
            <strong>{{ formatCount(result.advanced) }}</strong>
          </article>
          <article class="user-directory-summary-card is-neutral">
            <span>Not changed</span>
            <strong>{{ formatCount(result.skipped.length) }}</strong>
          </article>
        </div>

        <section v-if="result.skipped.length > 0" class="directory-section">
          <header class="directory-section-head">
            <div>
              <h4>Not changed</h4>
              <p>Their records had moved on by the time you confirmed, so they were left as they are.</p>
            </div>
          </header>
          <ul class="year-end-list">
            <li v-for="item in result.skipped" :key="item.id">
              <div class="year-end-row is-static">
                <span class="year-end-row-copy">
                  <strong>{{ item.name || `Account #${item.id}` }}</strong>
                  <span>{{ item.student_id || 'No student ID' }} · {{ item.reason }}</span>
                </span>
              </div>
            </li>
          </ul>
        </section>

        <p class="year-end-intro">
          Graduates are now under Archived records on the Users page, where any of them can be restored.
          The academic term shown across the platform is a separate setting; change it in Settings when the new year starts.
        </p>

        <div class="management-actions">
          <RouterLink :to="{ name: 'dashboard-users' }" class="primary-btn">Back to Users</RouterLink>
          <RouterLink :to="{ name: 'dashboard-settings' }" class="ghost-btn">Open Settings</RouterLink>
        </div>
      </div>

      <div v-else-if="students.length === 0 && !loadError" class="room-empty-state">
        <GraduationCap :size="42" />
        <h4>No current students</h4>
        <p>There is nobody enrolled to graduate or move up.</p>
      </div>

      <div v-else-if="students.length > 0" class="year-end-body">
        <p class="year-end-intro">
          This is a proposal from the year level on each student's record: fourth years and above graduate,
          everyone else moves up one year. Untick anyone it should not apply to, such as a student who is
          repeating or not finishing. Nothing changes until you confirm at the bottom.
        </p>

        <div
          v-if="lastClosed"
          class="feedback"
          :class="recentlyClosed ? 'danger' : 'info'"
          :role="recentlyClosed ? 'alert' : undefined"
        >
          <AlertCircle v-if="recentlyClosed" :size="15" />
          <Info v-else :size="15" />
          <span>
            The school year was last closed on {{ formatDateTime(lastClosed.at) }}<template v-if="lastClosed.by"> by {{ lastClosed.by }}</template>
            ({{ formatCount(lastClosed.graduated) }} graduated, {{ formatCount(lastClosed.advanced) }} moved up).
            <template v-if="recentlyClosed">Closing it again would move the same students up a second time.</template>
          </span>
        </div>

        <div class="user-directory-summary year-end-summary">
          <article class="user-directory-summary-card is-gold">
            <span>Will graduate</span>
            <strong>{{ formatCount(graduateCount) }}</strong>
          </article>
          <article class="user-directory-summary-card is-success">
            <span>Will move up a year</span>
            <strong>{{ formatCount(advanceCount) }}</strong>
          </article>
          <article class="user-directory-summary-card is-neutral">
            <span>Will stay as they are</span>
            <strong>{{ formatCount(stayCount) }}</strong>
          </article>
        </div>

        <!-- The registrar's list is the authority on who finished; this turns
             it into ticks instead of making someone find each name by hand. -->
        <details class="year-end-tool">
          <summary>
            <ListChecks :size="16" />
            Use the registrar's list of graduates
          </summary>
          <div class="year-end-tool-body">
            <p class="muted">
              Paste the student IDs of everyone who is graduating, or choose a .csv or .txt file that contains them.
              Finishing students on the list are ticked and the rest are unticked. Other year levels are not affected.
            </p>
            <textarea
              v-model="registrarListText"
              class="text-input textarea-input"
              rows="4"
              placeholder="e.g. 2300001, 2301291, 2301305"
              aria-label="Student IDs of graduating students"
            />
            <div class="year-end-tool-actions">
              <button type="button" class="primary-btn" :disabled="!registrarListText.trim()" @click="applyRegistrarList">
                Apply list
              </button>
              <label class="ghost-btn year-end-file">
                Choose file
                <input type="file" accept=".csv,.txt,text/csv,text/plain" @change="handleListFile" />
              </label>
              <button
                v-if="registrarListText || registrarListReport"
                type="button"
                class="ghost-btn"
                @click="clearRegistrarList"
              >
                Clear
              </button>
            </div>

            <div v-if="registrarListReport?.empty" class="feedback danger" role="alert">
              <AlertCircle :size="15" />
              <span>No student IDs were found in that list. A student ID is 7 to 10 digits.</span>
            </div>
            <template v-else-if="registrarListReport">
              <div class="feedback info">
                <Info :size="15" />
                <span>
                  {{ formatCount(registrarListReport.listed) }} IDs read:
                  {{ formatCount(registrarListReport.graduating) }} finishing students ticked to graduate,
                  {{ formatCount(registrarListReport.staying) }} left to stay enrolled.
                </span>
              </div>
              <div v-if="registrarListReport.unmatched.length > 0" class="feedback danger" role="alert">
                <AlertCircle :size="15" />
                <div>
                  <span>
                    {{ formatCount(registrarListReport.unmatched.length) }}
                    {{ registrarListReport.unmatched.length === 1 ? 'ID on the list was' : 'IDs on the list were' }}
                    not acted on:
                  </span>
                  <ul class="year-end-unmatched">
                    <li v-for="item in registrarListReport.unmatched" :key="item.student_id">
                      {{ item.student_id }} — {{ item.note }}
                    </li>
                  </ul>
                </div>
              </div>
            </template>
          </div>
        </details>

        <input
          v-model.trim="search"
          type="text"
          class="text-input"
          inputmode="search"
          enterkeyhint="search"
          placeholder="Find a student by name, ID or email"
          aria-label="Find a student"
        />

        <section v-for="group in groups" :key="group.key" class="directory-section year-end-group">
          <header class="directory-section-head">
            <div>
              <h4>{{ group.title }}</h4>
              <p>{{ group.description }}</p>
            </div>
            <span class="pill" :class="group.selectable ? 'navy' : 'neutral'">
              {{ group.selectable ? `${formatCount(group.selected)} of ${formatCount(group.total)}` : formatCount(group.total) }}
            </span>
          </header>

          <div class="year-end-group-actions">
            <button
              type="button"
              class="ghost-btn"
              :aria-expanded="isGroupOpen(group).toString()"
              @click="toggleGroup(group)"
            >
              {{ isGroupOpen(group) ? 'Hide students' : `Show ${formatCount(group.visible.length)} ${group.visible.length === 1 ? 'student' : 'students'}` }}
            </button>
            <button
              v-if="group.selectable && isGroupOpen(group) && group.visible.length > 0"
              type="button"
              class="ghost-btn"
              @click="setGroupSelection(group, !group.allVisibleSelected)"
            >
              {{ group.allVisibleSelected ? 'Untick all shown' : 'Tick all shown' }}
            </button>
          </div>

          <template v-if="isGroupOpen(group)">
            <p v-if="group.visible.length === 0" class="muted">No students in this group match the search.</p>
            <ul v-else class="year-end-list">
              <li v-for="student in group.visible" :key="student.id">
                <label
                  class="year-end-row"
                  :class="{ 'is-off': group.selectable && !selectedIds.has(student.id), 'is-static': !group.selectable }"
                >
                  <input
                    v-if="group.selectable"
                    type="checkbox"
                    :checked="selectedIds.has(student.id)"
                    @change="toggleStudent(student.id)"
                  />
                  <span class="year-end-row-copy">
                    <strong>{{ student.name }}</strong>
                    <span>{{ student.student_id || 'No student ID' }} · {{ student.email }}</span>
                  </span>
                  <span class="pill" :class="outcomeTone(group, student)">{{ outcomeLabel(group, student) }}</span>
                </label>
              </li>
            </ul>
          </template>
        </section>
      </div>
    </article>

    <!-- Outside the card on purpose: the card clips its contents, and a bar
         inside it would have nothing to stick to. -->
    <footer v-if="students.length > 0 && !result" class="year-end-bar">
      <p>
        <strong>{{ formatCount(graduateCount) }}</strong> <span class="year-end-bar-extra">will </span>graduate ·
        <strong>{{ formatCount(advanceCount) }}</strong> <span class="year-end-bar-extra">will </span>move up ·
        <strong>{{ formatCount(stayCount) }}</strong> <span class="year-end-bar-extra">will </span>stay<span class="year-end-bar-extra"> as they are</span>
      </p>
      <button type="button" class="primary-btn" :disabled="changeCount === 0" @click="openConfirm">
        Review and confirm
      </button>
    </footer>

    <teleport to="body">
      <div v-if="confirmOpen" class="modal-backdrop" @click.self="closeConfirm">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="year-end-confirm-title">
          <header class="modal-head">
            <h4 id="year-end-confirm-title">Close the school year?</h4>
            <button type="button" class="modal-close" aria-label="Close" :disabled="applying" @click="closeConfirm">
              <X :size="16" />
            </button>
          </header>

          <ul class="year-end-confirm-list">
            <li><strong>{{ formatCount(graduateCount) }}</strong> will be marked Graduated and leave the class lists.</li>
            <li><strong>{{ formatCount(advanceCount) }}</strong> will move up one year level.</li>
            <li><strong>{{ formatCount(stayCount) }}</strong> will not be changed.</li>
          </ul>

          <p class="muted">
            A graduate can be restored from Archived records on the Users page. A year level can only be put back
            by editing that student, so check the numbers before you confirm.
          </p>

          <div v-if="recentlyClosed" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>
              The school year was already closed on {{ formatDateTime(lastClosed.at) }}.
              Confirming moves these students up again.
            </span>
          </div>

          <div v-if="applyError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ applyError }}</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="applying" @click="closeConfirm">Cancel</button>
            <button type="button" class="primary-btn" :disabled="applying" @click="confirmYearEnd">
              <RefreshCw v-if="applying" :size="14" class="spin-soft" />
              <span>{{ applying ? 'Closing…' : 'Close School Year' }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { AlertCircle, ArrowLeft, CheckCircle2, GraduationCap, Info, ListChecks, RefreshCw, X } from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useYearEndModule, yearLevelLabel } from '../composables/useYearEndModule'

const {
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
  confirmOpen,
  applying,
  applyError,
  result,
  toggleStudent,
  setGroupSelection,
  applyRegistrarList,
  readRegistrarListFile,
  clearRegistrarList,
  openConfirm,
  closeConfirm,
  confirmYearEnd,
} = useYearEndModule()

// A year has two terms and a break, so anything closed inside the last six
// months is this same school year and almost certainly a repeat.
const RECENT_CLOSE_DAYS = 180

const recentlyClosed = computed(() => {
  const closedAt = Date.parse(lastClosed.value?.at ?? '')
  if (!Number.isFinite(closedAt)) return false

  return Date.now() - closedAt < RECENT_CLOSE_DAYS * 24 * 60 * 60 * 1000
})

// The finishing group is the one to check name by name, so it starts open.
// The years below are a few exceptions in a long list: closed until asked
// for, or until a search has something to show in them.
const toggledGroups = ref(new Set())

function isGroupOpen(group) {
  if (search.value) return true

  const openByDefault = group.key === 'graduate' || group.key === 'none'
  return toggledGroups.value.has(group.key) ? !openByDefault : openByDefault
}

function toggleGroup(group) {
  const next = new Set(toggledGroups.value)

  if (next.has(group.key)) next.delete(group.key)
  else next.add(group.key)

  toggledGroups.value = next
}

function outcomeLabel(group, student) {
  if (!group.selectable) return 'No change'
  if (!selectedIds.value.has(student.id)) return `Stays in ${yearLevelLabel(student.year_level)}`

  return student.proposed === 'graduate' ? 'Graduates' : `To ${yearLevelLabel(student.next_year_level)}`
}

function outcomeTone(group, student) {
  if (!group.selectable || !selectedIds.value.has(student.id)) return 'neutral'

  return student.proposed === 'graduate' ? 'gold' : 'success'
}

function handleListFile(event) {
  const [file] = event.target.files ?? []
  readRegistrarListFile(file)

  // So choosing the same file again after a correction still fires change.
  event.target.value = ''
}

function formatCount(value) {
  return Number(value ?? 0).toLocaleString()
}
</script>

<style scoped>
.year-end-view {
  gap: 14px;
}

.year-end-back {
  justify-self: start;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--lnu-navy-light);
  text-decoration: none;
}

.year-end-back:hover {
  text-decoration: underline;
}

/* The way back was a 20px-tall line of text on a touch screen. */
@media (pointer: coarse) {
  .year-end-back {
    min-height: 44px;
    margin-block: -8px;
  }
}

.year-end-body {
  display: grid;
  gap: 18px;
  padding: 6px 18px 22px;
}

.year-end-intro {
  margin: 0;
  max-width: 78ch;
  font-size: 14px;
  line-height: 1.55;
  color: var(--ink-muted);
}

.year-end-summary {
  margin-bottom: 0;
}

.year-end-tool {
  border: 1px solid var(--border-soft);
  border-radius: 14px;
  background: var(--surface-muted);
}

.year-end-tool > summary {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 14px;
  font-size: 14px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}

.year-end-tool-body {
  display: grid;
  gap: 10px;
  padding: 0 14px 14px;
}

.year-end-tool-body .muted {
  margin: 0;
}

.year-end-tool-actions,
.year-end-group-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

/* The real file input covers the button, so the whole button opens the
   picker and it still works from the keyboard. */
.year-end-file {
  position: relative;
  overflow: hidden;
  cursor: pointer;
}

.year-end-file input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}

.year-end-unmatched {
  margin: 6px 0 0;
  padding-left: 18px;
}

.year-end-list {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid var(--border-soft);
  border-radius: 14px;
  background: var(--surface);
  overflow: hidden;
}

.year-end-list li + li {
  border-top: 1px solid var(--border-soft);
}

.year-end-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  cursor: pointer;
}

.year-end-row.is-static {
  cursor: default;
}

.year-end-row input {
  flex-shrink: 0;
  width: 18px;
  height: 18px;
  accent-color: var(--lnu-navy);
}

.year-end-row-copy {
  flex: 1;
  min-width: 0;
  display: grid;
  gap: 2px;
}

.year-end-row-copy strong {
  font-size: 14px;
  color: var(--ink);
  overflow-wrap: anywhere;
}

.year-end-row-copy span {
  font-size: 12.5px;
  color: var(--ink-muted);
  overflow-wrap: anywhere;
}

.year-end-row.is-off .year-end-row-copy strong {
  color: var(--ink-muted);
}

.year-end-row .pill,
.year-end-group .directory-section-head .pill {
  flex-shrink: 0;
  white-space: nowrap;
}

.year-end-bar {
  position: sticky;
  bottom: 12px;
  z-index: 5;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px 16px;
  padding: 12px 16px;
  border: 1px solid var(--border-strong);
  border-radius: 16px;
  background: var(--surface);
  box-shadow: var(--shadow-md);
}

.year-end-bar p {
  margin: 0;
  font-size: 14px;
  color: var(--ink-muted);
}

.year-end-bar strong {
  color: var(--ink);
}

.year-end-confirm-list {
  margin: 0;
  padding-left: 18px;
  display: grid;
  gap: 6px;
  font-size: 14px;
  color: var(--ink-muted);
}

.year-end-confirm-list strong {
  color: var(--ink);
}

@media (max-width: 640px) {
  .year-end-body {
    padding: 4px 14px 18px;
  }

  .year-end-bar {
    bottom: 8px;
  }

  .year-end-bar .primary-btn {
    width: 100%;
    justify-content: center;
  }

  /* The words that only pad the sentence out go, so it fits on one line. */
  .year-end-bar-extra {
    display: none;
  }

  /* The outcome sits under the name: beside it, the name was squeezed into
     three lines and the email broke mid-word. */
  .year-end-row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: start;
    gap: 6px 12px;
  }

  .year-end-row input {
    margin-top: 2px;
  }

  .year-end-row .pill {
    grid-column: 2;
    justify-self: start;
  }

  .year-end-row.is-static {
    grid-template-columns: minmax(0, 1fr);
  }

  .year-end-row.is-static .pill {
    grid-column: 1;
  }
}
</style>
