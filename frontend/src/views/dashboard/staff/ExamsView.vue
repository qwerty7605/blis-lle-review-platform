<template>
  <section class="room-view">
    <div v-if="examMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ examMessage }}</span>
    </div>
    <div v-if="examError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ examError }}</span>
    </div>

    <!-- What this page is, the totals, and the one action that adds to it -->
    <header class="examlist-hero">
      <div class="examlist-hero-copy">
        <h2 class="examlist-hero-title">Exams</h2>
        <p class="examlist-hero-body">
          Build an exam from your question banks, then assign it to one or more rooms.
        </p>

        <ul v-if="exams.length > 0" class="examlist-hero-stats">
          <li><FileText :size="13" />{{ exams.length }} {{ exams.length === 1 ? 'exam' : 'exams' }}</li>
          <li><CircleDot :size="13" />{{ openExamCount }} open now</li>
          <li v-if="unassignedExamCount > 0"><DoorOpen :size="13" />{{ unassignedExamCount }} not assigned to a room</li>
        </ul>
      </div>

      <button type="button" class="examlist-hero-btn" :disabled="examLoading" @click="openCreateExamModal">
        <Plus :size="16" />
        Add Exam
      </button>
    </header>

    <div v-if="examLoading && exams.length === 0" class="examlist-state">
      <RefreshCw :size="30" class="spin-soft" />
      <strong>Loading exams</strong>
      <p>Please wait while we fetch exam records.</p>
    </div>

    <div v-else-if="exams.length === 0" class="examlist-state">
      <span class="examlist-state-icon"><FileText :size="24" /></span>
      <strong>No exams yet</strong>
      <p>Create an exam and assign it to one or more rooms.</p>
      <button type="button" class="primary-btn" @click="openCreateExamModal">
        <Plus :size="16" />
        Add Exam
      </button>
    </div>

    <template v-else>
      <!-- Find an exam: by name, or by the room it is assigned to -->
      <div class="examlist-toolbar">
        <label class="examlist-search">
          <Search :size="16" class="examlist-search-icon" />
          <input
            v-model.trim="examSearch"
            type="search"
            class="examlist-search-input"
            placeholder="Search by exam or subject"
            aria-label="Search exams"
          />
        </label>

        <div class="examlist-filters" role="group" aria-label="Filter by room">
          <button
            type="button"
            class="examlist-filter"
            :class="{ active: selectedRoomFilter === '' }"
            :aria-pressed="selectedRoomFilter === ''"
            @click="selectedRoomFilter = ''"
          >
            All rooms
            <span class="examlist-filter-count">{{ exams.length }}</span>
          </button>
          <button
            v-for="room in manageableRooms"
            :key="room.id"
            type="button"
            class="examlist-filter"
            :class="{ active: selectedRoomFilter === String(room.id) }"
            :aria-pressed="selectedRoomFilter === String(room.id)"
            @click="selectedRoomFilter = String(room.id)"
          >
            {{ room.name }}
            <span class="examlist-filter-count">{{ examCountForRoom(room.id) }}</span>
          </button>
        </div>
      </div>

      <!-- Exams exist, just none that match -- a different problem from
           having no exams at all, so it gets its own way out. -->
      <div v-if="filteredExams.length === 0" class="examlist-state">
        <span class="examlist-state-icon"><Search :size="22" /></span>
        <strong>{{ examSearch ? 'No exams match' : `No exams in ${selectedRoomName}` }}</strong>
        <p>
          {{
            examSearch
              ? 'Try a different search or room.'
              : 'This room has no exams assigned yet. Assign one by editing an exam, or switch back to all rooms.'
          }}
        </p>
        <button type="button" class="ghost-btn" @click="clearExamFilters">Clear filters</button>
      </div>

      <!-- Desktop and tablet: one row per exam. Phones keep the cards below,
           where a six-column table would only scroll sideways. -->
      <div v-else-if="!isPhone" class="examlist-table-card">
        <div class="examlist-table-scroll">
          <table class="examlist-table">
            <thead>
              <tr>
                <th scope="col">Exam</th>
                <th scope="col" class="is-number">Questions</th>
                <th scope="col" class="is-number">Minutes</th>
                <th scope="col">Rooms</th>
                <th scope="col">Schedule</th>
                <th scope="col" class="is-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="exam in filteredExams"
                :id="`exam-${exam.id}`"
                :key="exam.id"
                :class="{ 'is-highlighted': highlightedExamId === exam.id }"
              >
                <td>
                  <div class="examrow-cell">
                    <span class="examrow-icon" aria-hidden="true"><FileText :size="17" /></span>
                    <div class="examrow-cell-copy" :title="`Sources: ${examQuestionBankSummaryText(exam)}`">
                      <strong>{{ exam.title }}</strong>
                      <span>{{ examQuestionBankSubject(exam) }}</span>
                      <small>{{ examRulesText(exam) }}</small>
                    </div>
                  </div>
                </td>
                <td class="is-number"><strong class="examrow-number">{{ exam.total_items }}</strong></td>
                <td class="is-number">{{ exam.duration_minutes }}</td>
                <td>
                  <!-- Each chip opens that room's page directly. -->
                  <div v-if="exam.assigned_rooms?.length > 0 || exam.archived_rooms?.length > 0" class="examrow-rooms">
                    <router-link
                      v-for="room in exam.assigned_rooms"
                      :key="`room-${room.id}`"
                      class="assignment-chip"
                      :to="{ name: 'dashboard-room-management', query: { room: room.id } }"
                      :title="`Open ${room.name}`"
                    >
                      <DoorOpen :size="12" />
                      {{ room.name }}
                    </router-link>
                    <span
                      v-for="room in exam.archived_rooms"
                      :key="`archived-room-${room.id}`"
                      class="assignment-chip is-archived"
                      title="Archived from this room"
                    >
                      <Archive :size="12" />
                      {{ room.name }}
                    </span>
                  </div>
                  <span v-else class="examrow-muted">Not assigned</span>
                </td>
                <td>
                  <div class="examrow-schedule" :title="formatExamSchedule(exam.schedule_start_at ?? exam.scheduled_at, exam.schedule_end_at)">
                    <span class="examrow-status" :class="`is-${examWindow(exam).tone}`">
                      <i aria-hidden="true"></i>{{ examWindow(exam).label }}
                    </span>
                    <span class="examrow-muted">{{ examWindow(exam).dates }}</span>
                  </div>
                </td>
                <td class="is-actions">
                  <div class="examrow-actions">
                    <button type="button" class="ghost-btn" @click="openEditExamModal(exam)">
                      <Pencil :size="14" />
                      Edit
                    </button>
                    <button
                      type="button"
                      class="examrow-delete-btn"
                      :aria-label="`Delete ${exam.title}`"
                      title="Delete"
                      @click="openDeleteExamModal(exam)"
                    >
                      <Trash2 :size="15" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <ul v-else class="examlist-grid">
        <li
          v-for="exam in filteredExams"
          :id="`exam-${exam.id}`"
          :key="exam.id"
          class="examrow-card"
          :class="{ 'is-highlighted': highlightedExamId === exam.id }"
        >
          <div class="examrow-card-top">
            <span class="examrow-icon" aria-hidden="true"><FileText :size="18" /></span>
            <div class="examrow-card-heading">
              <h3>{{ exam.title }}</h3>
              <p>{{ examQuestionBankSubject(exam) }}</p>
            </div>
          </div>

          <dl class="examrow-facts">
            <div>
              <dt>Questions</dt>
              <dd>{{ exam.total_items }}</dd>
            </div>
            <div>
              <dt>Minutes</dt>
              <dd>{{ exam.duration_minutes }}</dd>
            </div>
            <div class="is-wide">
              <dt>Schedule</dt>
              <dd>
                <span class="examrow-status" :class="`is-${examWindow(exam).tone}`">
                  <i aria-hidden="true"></i>{{ examWindow(exam).label }}
                </span>
                <span class="examrow-muted">{{ examWindow(exam).dates }}</span>
              </dd>
            </div>
            <div class="is-wide">
              <dt>Rules</dt>
              <dd>{{ examRulesText(exam) }}</dd>
            </div>
          </dl>

          <div v-if="exam.assigned_rooms?.length > 0 || exam.archived_rooms?.length > 0" class="examrow-rooms">
            <router-link
              v-for="room in exam.assigned_rooms"
              :key="`room-${room.id}`"
              class="assignment-chip"
              :to="{ name: 'dashboard-room-management', query: { room: room.id } }"
            >
              <DoorOpen :size="12" />
              {{ room.name }}
            </router-link>
            <span
              v-for="room in exam.archived_rooms"
              :key="`archived-room-${room.id}`"
              class="assignment-chip is-archived"
            >
              <Archive :size="12" />
              {{ room.name }}
            </span>
          </div>
          <p v-else class="examrow-muted">Not assigned to any room</p>

          <div class="examrow-card-actions">
            <button type="button" class="primary-btn" @click="openEditExamModal(exam)">
              <Pencil :size="15" />
              Edit
            </button>
            <button
              type="button"
              class="examrow-delete-btn"
              :aria-label="`Delete ${exam.title}`"
              title="Delete"
              @click="openDeleteExamModal(exam)"
            >
              <Trash2 :size="16" />
            </button>
          </div>
        </li>
      </ul>
    </template>

    <teleport to="body">
      <div v-if="showExamModal" class="modal-backdrop exam-modal-backdrop" @click.self="closeExamModal">
        <div class="modal-card exam-modal-card">
          <header class="modal-head exam-modal-head">
            <div class="exam-modal-title-wrap">
              <div class="management-inline exam-modal-top-pills">
                <span class="pill success">{{ examForm.id ? 'Edit mode' : 'New exam' }}</span>
                <span class="pill neutral">{{ examQuestionBanks.length }} saved question set(s)</span>
              </div>
              <h4>{{ examForm.id ? 'Update Exam Setup' : 'Create Exam Setup' }}</h4>
              <p>Build the exam in sections, then review the live summary before saving.</p>
            </div>
            <button type="button" class="modal-close" @click="closeExamModal">
              <X :size="16" />
            </button>
          </header>

          <div class="exam-modal-body">
            <div class="exam-modal-layout">
              <div class="exam-modal-main">
                <section class="exam-modal-panel">
                  <div class="exam-panel-head">
                    <span class="exam-panel-kicker">Section 1</span>
                    <h5>Core Details</h5>
                    <p>Give the exam a clear identity and set the base workload.</p>
                  </div>

                  <div class="exam-panel-grid single">
                    <label class="field-stack">
                      <span class="field-label">Title</span>
                      <input v-model.trim="examForm.title" type="text" class="text-input" maxlength="255" />
                    </label>

                    <div class="inline-form exam-meta-row">
                      <label class="field-stack narrow">
                        <span class="field-label">Items</span>
                        <input v-model.number="examForm.total_items" type="number" min="1" max="1000" class="text-input" />
                      </label>
                      <label class="field-stack narrow">
                        <span class="field-label">Minutes</span>
                        <input v-model.number="examForm.duration_minutes" type="number" min="1" max="600" class="text-input" />
                      </label>
                    </div>

                    <label class="field-stack">
                      <span class="field-label">Description</span>
                      <textarea v-model.trim="examForm.description" class="text-input textarea-input" rows="4" />
                    </label>
                  </div>
                </section>

                <section class="exam-modal-panel">
                  <div class="exam-panel-head">
                    <span class="exam-panel-kicker">Section 2</span>
                    <h5>Question Set Mix</h5>
                    <p>Choose one or more sources. Students will receive items from the combined pool.</p>
                  </div>

                  <div class="exam-selection-note">
                    <span class="pill success">{{ selectedQuestionBankSubject || 'General' }}</span>
                    <span class="pill neutral">{{ selectedQuestionBankTotalItems }} combined items</span>
                    <span class="pill navy">{{ selectedQuestionBanks.length }} selected</span>
                  </div>

                  <p v-if="examQuestionBanks.length === 0" class="muted">No saved question sets yet. Add one from Library first.</p>
                  <div
                    v-else
                    ref="questionSetDropdownRef"
                    class="exam-bank-dropdown"
                    @keydown.escape.stop.prevent="closeQuestionSetDropdown"
                  >
                    <button
                      type="button"
                      class="exam-bank-trigger"
                      :class="{ 'is-open': questionSetDropdownOpen }"
                      :aria-expanded="questionSetDropdownOpen ? 'true' : 'false'"
                      @click="toggleQuestionSetDropdown"
                    >
                      <span class="exam-bank-trigger-copy">
                        <span class="exam-bank-trigger-label">Question set selector</span>
                        <strong>{{ questionSetDropdownTitle }}</strong>
                        <small>{{ questionSetDropdownSubtitle }}</small>
                      </span>
                      <span class="exam-bank-trigger-side">
                        <span class="exam-bank-trigger-count">{{ selectedQuestionBanks.length }}</span>
                        <ChevronDown :size="18" class="exam-bank-trigger-icon" :class="{ 'is-open': questionSetDropdownOpen }" />
                      </span>
                    </button>

                    <div v-if="questionSetDropdownOpen" class="exam-bank-menu">
                      <div class="exam-bank-menu-head">
                        <div class="exam-bank-menu-copy">
                          <strong>Select question sets</strong>
                          <small>Check one or more sources to build the exam pool.</small>
                        </div>
                        <button
                          v-if="selectedQuestionBanks.length > 0"
                          type="button"
                          class="exam-bank-clear-btn"
                          @click="clearSelectedQuestionBanks"
                        >
                          Clear
                        </button>
                      </div>

                      <div class="exam-bank-menu-scroll">
                        <div class="exam-bank-groups">
                          <section v-for="group in groupedExamQuestionBanks" :key="group.label" class="exam-bank-group">
                            <strong class="exam-bank-group-title">{{ group.label }}</strong>
                            <div class="exam-bank-grid">
                              <label
                                v-for="bank in group.banks"
                                :key="bank.id"
                                class="check-item exam-bank-choice"
                                :class="{ 'is-selected': examForm.question_bank_ids.includes(bank.id) }"
                              >
                                <input v-model="examForm.question_bank_ids" type="checkbox" :value="bank.id" />
                                <span class="exam-bank-copy">
                                  <strong>{{ bank.title }}</strong>
                                  <small>{{ bank.total_items }} items</small>
                                </span>
                              </label>
                            </div>
                          </section>
                        </div>
                      </div>

                      <div class="exam-bank-menu-foot">
                        <span>{{ selectedQuestionBanks.length }} selected</span>
                        <span>{{ selectedQuestionBankTotalItems }} combined items</span>
                      </div>
                    </div>
                  </div>
                </section>

                <section class="exam-modal-panel">
                  <div class="exam-panel-head">
                    <span class="exam-panel-kicker">Section 3</span>
                    <h5>Availability And Rules</h5>
                    <p>Control when the exam opens and how many times students can take it.</p>
                  </div>

                  <div class="exam-panel-grid">
                    <div class="field-stack">
                      <span class="field-label">Schedule Window (optional)</span>
                      <div class="exam-schedule-row">
                        <label class="field-stack">
                          <span class="field-label">Start</span>
                          <input
                            v-model="examForm.schedule_start_at"
                            type="datetime-local"
                            class="text-input"
                            :min="createExamScheduleMin || undefined"
                          />
                        </label>
                        <label class="field-stack">
                          <span class="field-label">End</span>
                          <input
                            v-model="examForm.schedule_end_at"
                            type="datetime-local"
                            class="text-input"
                            :min="createExamScheduleEndMin || undefined"
                          />
                        </label>
                      </div>
                      <small class="muted">Leave both blank for always-available exams.</small>
                    </div>

                    <div class="field-stack">
                      <span class="field-label">Exam Attempt Options</span>
                      <div class="exam-policy-list">
                        <label class="check-item exam-policy-item">
                          <input v-model="examForm.one_take_only" type="checkbox" />
                          <span>
                            <strong>One take only</strong>
                            <small>Students cannot retake after submitting.</small>
                          </span>
                        </label>
                        <label class="check-item exam-policy-item">
                          <input v-model="examForm.shuffle_questions" type="checkbox" />
                          <span>
                            <strong>Shuffle questions</strong>
                            <small>Each attempt gets a randomized order when possible.</small>
                          </span>
                        </label>
                      </div>
                    </div>

                    <div class="field-stack" style="margin-top: 16px;">
                      <span class="field-label">Results Visibility</span>
                      <div class="exam-policy-list">
                        <label class="check-item exam-policy-item">
                          <input v-model="examForm.results_visibility_mode" type="radio" value="hidden" />
                          <span>
                            <strong>Hidden</strong>
                            <small>Students only see final scores, not individual correct answers.</small>
                          </span>
                        </label>
                        <label class="check-item exam-policy-item">
                          <input v-model="examForm.results_visibility_mode" type="radio" value="visible" />
                          <span>
                            <strong>Visible</strong>
                            <small>Students can review the exam and see right/wrong answers.</small>
                          </span>
                        </label>
                      </div>
                    </div>
                  </div>
                </section>

                <section class="exam-modal-panel">
                  <div class="exam-panel-head">
                    <span class="exam-panel-kicker">Section 4</span>
                    <h5>Room Assignment</h5>
                    <p>Pick the rooms that should immediately receive this exam.</p>
                  </div>

                  <div v-if="manageableRooms.length === 0" class="muted">No rooms available for assignment.</div>
                  <div v-else class="check-grid exam-room-grid">
                    <label v-for="room in manageableRooms" :key="room.id" class="check-item exam-room-choice">
                      <input v-model="examForm.room_ids" type="checkbox" :value="room.id" />
                      <span class="exam-room-copy">
                        <strong>{{ room.name }}</strong>
                        <small>{{ room.code }}</small>
                      </span>
                    </label>
                  </div>
                </section>
              </div>

              <aside class="exam-modal-side">
                <section class="exam-modal-summary">
                  <div class="exam-summary-hero">
                    <span class="exam-summary-kicker">Live Preview</span>
                    <strong>{{ examForm.title.trim() || 'Untitled exam' }}</strong>
                    <p>
                      {{ examForm.description.trim() || 'Add a short description so the purpose of the exam is easy to recognize.' }}
                    </p>
                  </div>

                  <div class="exam-summary-stats">
                    <article class="exam-summary-stat">
                      <span>Items</span>
                      <strong>{{ examForm.total_items || 0 }}</strong>
                    </article>
                    <article class="exam-summary-stat">
                      <span>Minutes</span>
                      <strong>{{ examForm.duration_minutes || 0 }}</strong>
                    </article>
                    <article class="exam-summary-stat">
                      <span>Sets</span>
                      <strong>{{ selectedQuestionBanks.length }}</strong>
                    </article>
                    <article class="exam-summary-stat">
                      <span>Rooms</span>
                      <strong>{{ examForm.room_ids.length }}</strong>
                    </article>
                  </div>

                  <div class="exam-summary-block">
                    <span class="exam-summary-label">Question Set Coverage</span>
                    <p>
                      <strong>{{ selectedQuestionBankTotalItems }}</strong> combined source items available
                    </p>
                    <small v-if="selectedQuestionBanks.length === 0">No question sets selected yet.</small>
                    <small v-else-if="selectedQuestionBankTotalItems < examForm.total_items">
                      Current selection is smaller than the requested item count.
                    </small>
                    <small v-else>
                      Selection is large enough for the requested item count.
                    </small>
                  </div>

                  <div class="exam-summary-block">
                    <span class="exam-summary-label">Schedule</span>
                    <p>{{ formatExamSchedule(examForm.schedule_start_at, examForm.schedule_end_at) }}</p>
                    <small>
                      {{
                        examForm.one_take_only
                          ? 'Students get one submission only.'
                          : 'Students can submit once and retake one more time.'
                      }}
                    </small>
                  </div>

                  <div class="exam-summary-block">
                    <span class="exam-summary-label">Selected Question Sets</span>
                    <div v-if="selectedQuestionBanks.length === 0" class="exam-summary-empty">
                      No question sets selected yet.
                    </div>
                    <div v-else class="exam-summary-stack">
                      <article v-for="bank in selectedQuestionBanks" :key="bank.id" class="exam-summary-chip">
                        <strong>{{ bank.title }}</strong>
                        <small>{{ bank.subject || 'General' }} | {{ bank.total_items }} items</small>
                      </article>
                    </div>
                  </div>

                  <div class="exam-summary-block">
                    <span class="exam-summary-label">Assigned Rooms</span>
                    <div v-if="examForm.room_ids.length === 0" class="exam-summary-empty">
                      No rooms selected yet.
                    </div>
                    <div v-else class="exam-summary-stack compact">
                      <article
                        v-for="room in manageableRooms.filter((room) => examForm.room_ids.includes(room.id))"
                        :key="room.id"
                        class="exam-summary-chip compact"
                      >
                        <strong>{{ room.name }}</strong>
                        <small>{{ room.code }}</small>
                      </article>
                    </div>
                  </div>
                </section>
              </aside>
            </div>
          </div>

          <div class="modal-actions exam-modal-actions">
            <p class="exam-modal-action-note">
              Choose enough question sets to cover the requested item count before saving.
            </p>
            <div class="exam-modal-action-buttons">
              <button type="button" class="ghost-btn" :disabled="examSaving" @click="closeExamModal">Cancel</button>
              <button
                type="button"
                class="primary-btn"
                :disabled="examSaving || !examForm.title.trim() || examForm.total_items < 1 || examForm.duration_minutes < 1"
                @click="handleSaveExam"
              >
                <RefreshCw v-if="examSaving" :size="15" class="spin-soft" />
                <span>{{ examSaving ? 'Saving...' : (examForm.id ? 'Update Exam' : 'Create Exam') }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showDeleteExamModal" class="modal-backdrop" @click.self="closeDeleteExamModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Delete Exam</h4>
            <button type="button" class="modal-close" @click="closeDeleteExamModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            Delete <strong>{{ selectedExam?.title }}</strong>? This also removes its room assignments.
          </p>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="examSaving" @click="closeDeleteExamModal">Cancel</button>
            <button type="button" class="danger-btn" :disabled="examSaving" @click="handleDeleteExam">
              <Trash2 :size="15" />
              Delete
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  AlertCircle,
  Archive,
  CheckCircle2,
  ChevronDown,
  CircleDot,
  DoorOpen,
  FileText,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  X,
} from 'lucide-vue-next'
import {
  examDeliveryModeLabel,
  formatCompactDate,
  formatExamSchedule,
  parseDateTime,
} from '../composables/useDashboardFormatters'
import { usePhoneLayout } from '../composables/useViewport'
import { useExamsModule } from '../composables/useExamsModule'

const route = useRoute()
const highlightedExamId = ref(null)

/** '' = All rooms; otherwise a room id, held as a string. */
const selectedRoomFilter = ref('')
const examSearch = ref('')

// Phones (640px and under) show the exams as cards; wider screens as a table.
const isPhone = usePhoneLayout()

const {
  exams,
  manageableRooms,
  examQuestionBanks,
  groupedExamQuestionBanks,
  selectedQuestionBanks,
  selectedQuestionBankSubject,
  selectedQuestionBankTotalItems,
  createExamScheduleMin,
  createExamScheduleEndMin,
  examLoading,
  examSaving,
  examError,
  examMessage,
  showExamModal,
  showDeleteExamModal,
  selectedExam,
  examForm,
  examQuestionBankCount,
  examQuestionBankSubject,
  examQuestionBankSummaryText,
  openCreateExamModal,
  openEditExamModal,
  closeExamModal,
  openDeleteExamModal,
  closeDeleteExamModal,
  handleSaveExam,
  handleDeleteExam,
} = useExamsModule()

const selectedRoomName = computed(() => {
  const room = manageableRooms.value.find((entry) => String(entry.id) === selectedRoomFilter.value)
  if (!room) return 'this room'

  return room.code ? `${room.name} (${room.code})` : room.name
})

/*
 * Matches on the exam's *active* assignments only: an exam archived out of
 * a room is deliberately not "in" that room any more, which is the same
 * reading the Rooms page uses when it files those under Archived.
 * An exam in several rooms shows up under each of them.
 */
const filteredExams = computed(() => {
  const needle = examSearch.value.toLowerCase()

  return exams.value.filter((exam) => {
    if (selectedRoomFilter.value !== ''
      && !(exam.assigned_rooms ?? []).some((room) => String(room.id) === selectedRoomFilter.value)) {
      return false
    }

    if (!needle) return true

    return [exam.title, exam.subject, examQuestionBankSubject(exam)]
      .some((value) => String(value ?? '').toLowerCase().includes(needle))
  })
})

function examCountForRoom(roomId) {
  return exams.value.filter((exam) => (
    (exam.assigned_rooms ?? []).some((room) => String(room.id) === String(roomId))
  )).length
}

function clearExamFilters() {
  examSearch.value = ''
  selectedRoomFilter.value = ''
}

/*
 * Where an exam is in its window, in one word plus the dates. "Open" covers
 * both a window that has started and an exam with no schedule at all, since
 * neither stops a student starting it.
 */
function examWindow(exam) {
  const start = parseDateTime(exam.schedule_start_at ?? exam.scheduled_at)
  const end = parseDateTime(exam.schedule_end_at)
  const now = Date.now()

  let dates = 'No schedule set'
  if (start && end) dates = `${formatCompactDate(start)} to ${formatCompactDate(end)}`
  else if (start) dates = `From ${formatCompactDate(start)}`
  else if (end) dates = `Until ${formatCompactDate(end)}`

  if (start && start.getTime() > now) return { tone: 'upcoming', label: 'Upcoming', dates }
  if (end && end.getTime() < now) return { tone: 'ended', label: 'Ended', dates }

  return { tone: 'open', label: 'Open', dates }
}

const openExamCount = computed(() => exams.value.filter((exam) => examWindow(exam).tone === 'open').length)

const unassignedExamCount = computed(() => (
  exams.value.filter((exam) => (exam.assigned_rooms ?? []).length === 0).length
))

// The three exam settings as one short line.
function examRulesText(exam) {
  return [
    examDeliveryModeLabel(exam.delivery_mode),
    exam.one_take_only ? 'One take only' : 'Retakes allowed',
    exam.shuffle_questions ? 'Shuffled' : 'Fixed order',
  ].join(' · ')
}
/*
 * A room that gets deleted while its filter is active would otherwise leave
 * the list stuck empty with no matching option in the dropdown.
 */
watch([manageableRooms, selectedRoomFilter], () => {
  if (selectedRoomFilter.value === '') return

  const stillExists = manageableRooms.value.some((room) => String(room.id) === selectedRoomFilter.value)
  if (!stillExists) {
    selectedRoomFilter.value = ''
  }
})

/*
 * Deep link back from the Rooms page's "Open exam" action (?exam=<id>).
 * Waits on the list actually containing that exam -- the query is read
 * before the fetch resolves -- then highlights and scrolls to it rather
 * than dropping the teacher at the top of an unfiltered list.
 */
watch(
  () => [exams.value.length, route.query?.exam],
  () => {
    const requestedId = Number(route.query?.exam)

    if (!Number.isFinite(requestedId) || requestedId < 1) {
      highlightedExamId.value = null
      return
    }

    if (!exams.value.some((exam) => Number(exam.id) === requestedId)) return

    highlightedExamId.value = requestedId

    // The deep link names one specific exam, so an active room filter that
    // would hide it gets cleared -- otherwise the link lands on an empty
    // list with no indication of why.
    selectedRoomFilter.value = ''
    examSearch.value = ''

    nextTick(() => {
      document
        .getElementById(`exam-${requestedId}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    })
  },
  { immediate: true },
)

const questionSetDropdownRef = ref(null)
const questionSetDropdownOpen = ref(false)

const questionSetDropdownTitle = computed(() => {
  if (selectedQuestionBanks.value.length === 0) {
    return 'Choose question sets'
  }

  if (selectedQuestionBanks.value.length === 1) {
    return selectedQuestionBanks.value[0].title
  }

  return `${selectedQuestionBanks.value.length} question sets selected`
})

const questionSetDropdownSubtitle = computed(() => {
  if (selectedQuestionBanks.value.length === 0) {
    return 'Open this dropdown and tick one or more question sets.'
  }

  const previewNames = selectedQuestionBanks.value
    .slice(0, 2)
    .map((bank) => bank.title)
    .join(' | ')

  const remainingCount = selectedQuestionBanks.value.length - Math.min(selectedQuestionBanks.value.length, 2)
  const combinedItemsText = `${selectedQuestionBankTotalItems.value} combined items`

  if (remainingCount > 0) {
    return `${previewNames} | +${remainingCount} more | ${combinedItemsText}`
  }

  return `${previewNames} | ${combinedItemsText}`
})

const toggleQuestionSetDropdown = () => {
  questionSetDropdownOpen.value = !questionSetDropdownOpen.value
}

const closeQuestionSetDropdown = () => {
  questionSetDropdownOpen.value = false
}

const clearSelectedQuestionBanks = () => {
  examForm.question_bank_ids = []
}

const handleQuestionSetDropdownPointerDown = (event) => {
  if (!questionSetDropdownOpen.value) {
    return
  }

  if (
    questionSetDropdownRef.value &&
    event.target instanceof Node &&
    questionSetDropdownRef.value.contains(event.target)
  ) {
    return
  }

  closeQuestionSetDropdown()
}

const handleQuestionSetDropdownKeydown = (event) => {
  if (event.key === 'Escape') {
    closeQuestionSetDropdown()
  }
}

watch(showExamModal, (isOpen) => {
  if (!isOpen) {
    closeQuestionSetDropdown()
  }
})

onMounted(() => {
  document.addEventListener('pointerdown', handleQuestionSetDropdownPointerDown)
  document.addEventListener('keydown', handleQuestionSetDropdownKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', handleQuestionSetDropdownPointerDown)
  document.removeEventListener('keydown', handleQuestionSetDropdownKeydown)
})
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
/* =====================================================================
   Exam list: a navy header with the totals, a search and room filter,
   then one row per exam (cards on phones). Same visual language as the
   rest of the dashboard (see the BRAND LAYER in dashboard.css).
   ===================================================================== */
.room-view {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 16px;
}

/* ---------- Header ---------- */
.examlist-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  flex-wrap: wrap;
  padding: 26px 28px;
  border-radius: 22px;
  color: #fff;
  background: var(--panel-navy);
  box-shadow: var(--shadow-md);
}

.examlist-hero-copy {
  flex: 1 1 340px;
  min-width: 0;
}

.examlist-hero-title {
  margin: 0;
  font-size: clamp(20px, 2.2vw, 26px);
  font-weight: 600;
  line-height: 1.25;
}

.examlist-hero-body {
  margin: 6px 0 0;
  max-width: 60ch;
  font-size: 14px;
  line-height: 1.6;
  color: rgba(255, 255, 255, 0.82);
}

.examlist-hero-stats {
  list-style: none;
  margin: 14px 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.examlist-hero-stats li {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 11px;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.16);
  background: rgba(255, 255, 255, 0.07);
  font-size: 12px;
  font-weight: 500;
}

.examlist-hero-stats svg {
  color: var(--lnu-gold-light);
}

.examlist-hero-btn {
  flex-shrink: 0;
  height: 44px;
  padding: 0 18px;
  border: none;
  border-radius: 10px;
  background: var(--lnu-gold-light);
  color: var(--lnu-navy-deep);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  transition: transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
}

.examlist-hero-btn:hover {
  background: #f6dc9a;
  box-shadow: 0 10px 18px rgba(0, 0, 0, 0.18);
  transform: translateY(-1px);
}

.examlist-hero-btn:focus-visible {
  outline: 2px solid var(--lnu-gold-light);
  outline-offset: 2px;
}

/* ---------- Loading / empty ---------- */
.examlist-state {
  display: grid;
  justify-items: center;
  gap: 10px;
  padding: 48px 20px;
  border-radius: 20px;
  border: 1px dashed rgba(26, 35, 126, 0.16);
  background: var(--surface);
  color: var(--lnu-text-muted);
  text-align: center;
}

.examlist-state strong {
  font-size: 16px;
  font-weight: 600;
  color: #0b0d17;
}

.examlist-state p {
  margin: 0;
  max-width: 44ch;
  font-size: 13.5px;
  line-height: 1.6;
}

.examlist-state-icon {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: rgba(26, 35, 126, 0.08);
  color: var(--lnu-navy);
}

/* ---------- Search and subject filter ---------- */
.examlist-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 10px;
}

.examlist-search {
  position: relative;
  width: 100%;
  max-width: 380px;
}

.examlist-search-icon {
  position: absolute;
  left: 13px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lnu-navy-light);
  opacity: 0.8;
  pointer-events: none;
}

.examlist-search-input {
  width: 100%;
  height: 42px;
  padding: 0 12px 0 38px;
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  background: var(--surface);
  font-size: 13.5px;
  color: var(--lnu-text);
  box-shadow: 0 1px 2px rgba(13, 21, 71, 0.05);
}

.examlist-search-input:focus {
  outline: none;
  border-color: var(--lnu-navy);
  box-shadow: var(--focus-ring);
}

.examlist-filters {
  flex: 1;
  min-width: 0;
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.examlist-filter {
  height: 36px;
  padding: 0 12px;
  border: 1px solid var(--border-soft);
  border-radius: 999px;
  background: var(--surface);
  color: var(--lnu-text-muted);
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}

.examlist-filter:hover:not(.active) {
  border-color: rgba(26, 35, 126, 0.3);
  color: var(--lnu-navy);
}

.examlist-filter.active {
  border-color: var(--lnu-navy);
  background: var(--lnu-navy);
  color: var(--lnu-gold-light);
  font-weight: 600;
}

.examlist-filter-count {
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.1);
  font-size: 11px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.examlist-filter.active .examlist-filter-count {
  background: rgba(255, 255, 255, 0.2);
}

/* ---------- Exam cards (phones) ---------- */
.examlist-grid {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 14px;
}

/* Each card is a column so the action row sits at the bottom of every card
   in a row, whatever the title length. */
.examrow-card {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 20px;
  border-radius: 20px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
}

.examrow-card-top {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.examrow-icon {
  flex-shrink: 0;
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(26, 35, 126, 0.08);
  color: var(--lnu-navy);
}

.examrow-card-heading {
  flex: 1;
  min-width: 0;
}

.examrow-facts {
  margin: 0;
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
  gap: 10px;
}

.examrow-facts > div {
  min-width: 0;
}

.examrow-facts dt {
  font-size: 11.5px;
  font-weight: 500;
  color: var(--lnu-text-muted);
}

.examrow-facts dd {
  margin: 2px 0 0;
  font-size: 13px;
  font-weight: 500;
  color: var(--lnu-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.examrow-actions {
  margin-top: auto;
  display: flex;
  align-items: center;
  gap: 8px;
}

.examrow-actions .ghost-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}

/* Quiet until hovered: deleting is rare, and a filled red button on every
   card made it the loudest thing on the page. */
.examrow-delete-btn {
  flex-shrink: 0;
  width: var(--control-height);
  height: var(--control-height);
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  background: var(--surface);
  color: var(--lnu-text-muted);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}

.examrow-delete-btn:hover:not(:disabled) {
  border-color: rgba(198, 40, 40, 0.4);
  background: rgba(198, 40, 40, 0.08);
  color: var(--lnu-danger);
}

.examrow-delete-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.examrow-delete-btn:focus-visible,
.examlist-filter:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

/* ---------- Narrower screens ---------- */
@media (max-width: 640px) {
  .examlist-hero {
    padding: 20px 18px;
    border-radius: 20px;
  }

  .examlist-hero-btn {
    width: 100%;
  }

  .examlist-search {
    max-width: none;
  }

  /* One swipeable row of rooms instead of several wrapped lines of chips. */
  .examlist-filters {
    flex-wrap: nowrap;
    overflow-x: auto;
    scrollbar-width: none;
    padding-bottom: 2px;
  }

  .examlist-filters::-webkit-scrollbar {
    display: none;
  }

  .examlist-filter {
    height: 40px;
  }

  .examrow-card {
    padding: 18px 16px;
    border-radius: 18px;
  }

  .examrow-facts {
    grid-template-columns: minmax(0, 1fr);
  }

  .examrow-actions {
    flex-wrap: wrap;
  }

  .examrow-actions .ghost-btn {
    flex: 1;
    justify-content: center;
  }
}

/* ---------- Exam table (desktop and tablet) ---------- */
.examlist-table-card {
  border-radius: 20px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

/* The table keeps a readable minimum width and scrolls inside its card on a
   narrow tablet, rather than squeezing six columns into unreadable slivers. */
.examlist-table-scroll {
  overflow-x: auto;
}

.examlist-table {
  width: 100%;
  min-width: 860px;
  border-collapse: collapse;
  font-size: 13.5px;
}

.examlist-table th,
.examlist-table td {
  padding: 14px 16px;
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid var(--border-soft);
}

.examlist-table thead th {
  padding-top: 13px;
  padding-bottom: 13px;
  background: var(--surface-muted);
  border-bottom-color: var(--border-strong);
  font-size: 12px;
  font-weight: 600;
  color: var(--lnu-text-muted);
  white-space: nowrap;
}

.examlist-table tbody tr:last-child td {
  border-bottom: none;
}

.examlist-table tbody tr:hover td {
  background: rgba(26, 35, 126, 0.03);
}

.examlist-table .is-number {
  text-align: right;
}

.examlist-table .is-actions {
  text-align: right;
  white-space: nowrap;
}

.examrow-cell {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.examrow-cell .examrow-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
}

.examrow-cell-copy {
  min-width: 0;
  display: grid;
  gap: 1px;
}

.examrow-cell-copy strong {
  font-size: 14px;
  font-weight: 600;
  line-height: 1.35;
  color: #0b0d17;
  overflow-wrap: anywhere;
}

.examrow-cell-copy span {
  font-size: 12.5px;
  color: var(--lnu-text-muted);
}

.examrow-number {
  font-size: 16px;
  font-weight: 600;
  color: var(--lnu-navy);
}

/* A text link, not another boxed button: the row already has three. */
.examrow-actions {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.examrow-actions .primary-btn,
.examrow-actions .ghost-btn {
  height: 34px;
  padding: 0 12px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}

.examrow-actions .examrow-delete-btn {
  width: 34px;
  height: 34px;
}

.examlist-table th:first-child,
.examlist-table td:first-child {
  width: 34%;
}

/* ---------- Exam-specific pieces ---------- */
.examrow-cell-copy small {
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.examrow-muted {
  font-size: 12.5px;
  color: var(--lnu-text-muted);
}

.examrow-rooms {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.examrow-schedule {
  display: grid;
  gap: 2px;
}

/* Open / Upcoming / Ended, as a dot and a word rather than colour alone. */
.examrow-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: var(--lnu-text);
  white-space: nowrap;
}

.examrow-status i {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--lnu-gray-dark);
}

.examrow-status.is-open i { background: var(--lnu-success); }
.examrow-status.is-upcoming i { background: var(--lnu-navy-light); }
.examrow-status.is-ended { color: var(--lnu-text-muted); }

/* The exam a link from the Rooms page pointed at. */
.examlist-table tbody tr.is-highlighted td {
  background: rgba(240, 208, 128, 0.22);
}

.examrow-card.is-highlighted {
  border-color: var(--lnu-gold);
  box-shadow: 0 0 0 3px rgba(240, 208, 128, 0.4);
}

.examrow-card-heading {
  flex: 1;
  min-width: 0;
}

.examrow-card-heading h3 {
  margin: 0;
  font-size: 15.5px;
  font-weight: 600;
  line-height: 1.35;
  color: #0b0d17;
  overflow-wrap: anywhere;
}

.examrow-card-heading p {
  margin: 2px 0 0;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.examrow-facts {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.examrow-facts .is-wide {
  grid-column: 1 / -1;
}

.examrow-facts dd {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 10px;
  white-space: normal;
}

.examrow-card-actions {
  margin-top: auto;
  display: flex;
  align-items: center;
  gap: 8px;
}

.examrow-card-actions .primary-btn {
  flex: 1;
  height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
}

.examrow-card-actions .examrow-delete-btn {
  width: 44px;
  height: 44px;
}
</style>
