<template>
  <section ref="pageRef" class="room-view">
    <div v-if="examMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ examMessage }}</span>
    </div>
    <!-- While the form is open it covers this banner, so the form shows the
         error itself (see its footer). -->
    <div v-if="examError && !showExamModal" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ examError }}</span>
    </div>

    <!-- What this page is, the totals, and the one action that adds to it -->
    <header class="examlist-hero">
      <div class="examlist-hero-copy">
        <h2 class="examlist-hero-title">
          Exams
          <InfoTip on-dark text="Build an exam from your question banks, then assign it to one or more rooms." />
        </h2>

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

        <OwnerFilter v-if="seesEveryTeacher" v-model="selectedOwnerFilter" :owners="examOwners" label="Exams by" />

        <div v-scroll-hint class="examlist-filters" role="group" aria-label="Filter by room">
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

      <!-- A table where its six columns fit. Anywhere narrower (a phone, a
           tablet, a small laptop with the sidebar open) the rows below. -->
      <div v-else-if="!useRows" class="examlist-table-card">
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
                      <small v-if="seesEveryTeacher && exam.creator?.name" class="owner-line">By {{ exam.creator.name }}</small>
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

      <!-- One line per exam, opening in place for the rest and for Edit and
           Delete. The cards this replaces were about 480px each, which made
           67 exams a 35,000px page on a phone. -->
      <div v-else class="examlist-rows-card">
        <ul class="directory-row-list examlist-rows">
          <li v-for="exam in filteredExams" :id="`exam-${exam.id}`" :key="exam.id">
            <details
              class="directory-row-details"
              :class="{ 'is-highlighted': highlightedExamId === exam.id }"
              :open="highlightedExamId === exam.id"
            >
              <summary class="directory-row">
                <span class="directory-row-copy">
                  <strong>{{ exam.title }}</strong>
                  <span>{{ examRowMeta(exam) }}</span>
                </span>
                <span class="pill" :class="examWindowPill(exam)">{{ examWindow(exam).label }}</span>
                <ChevronDown :size="18" class="directory-row-more directory-row-chevron" aria-hidden="true" />
              </summary>

              <dl class="directory-row-facts">
                <div>
                  <dt>Subject</dt>
                  <dd>{{ examQuestionBankSubject(exam) }}</dd>
                </div>
                <div>
                  <dt>Schedule</dt>
                  <dd>{{ examWindow(exam).dates }}</dd>
                </div>
                <div class="is-wide">
                  <dt>Rules</dt>
                  <dd>{{ examRulesText(exam) }}</dd>
                </div>
                <div class="is-full">
                  <dt>Rooms</dt>
                  <dd>
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
                    <template v-else>Not assigned to any room</template>
                  </dd>
                </div>
              </dl>

              <div class="directory-row-actions examlist-row-actions">
                <button
                  type="button"
                  class="examrow-delete-btn"
                  :aria-label="`Delete ${exam.title}`"
                  title="Delete"
                  @click="openDeleteExamModal(exam)"
                >
                  <Trash2 :size="16" />
                </button>
                <button type="button" class="primary-btn" @click="openEditExamModal(exam)">
                  <Pencil :size="15" />
                  Edit
                </button>
              </div>
            </details>
          </li>
        </ul>
      </div>
    </template>

    <teleport to="body">
      <div v-if="showExamModal" class="modal-backdrop exam-modal-backdrop" @click.self="dismissExamModal">
        <div class="modal-card exam-modal-card" role="dialog" aria-modal="true" aria-labelledby="exam-form-title">
          <header class="modal-head exam-modal-head">
            <div class="exam-modal-title-wrap">
              <div class="management-inline exam-modal-top-pills">
                <span class="pill success">{{ examForm.id ? 'Edit mode' : 'New exam' }}</span>
                <span class="pill neutral">{{ examQuestionBanks.length }} saved question set(s)</span>
              </div>
              <h4 id="exam-form-title">
                {{ examForm.id ? 'Update Exam Setup' : 'Create Exam Setup' }}
                <InfoTip text="Build the exam in sections, then review the live summary before saving." />
              </h4>
            </div>
            <button type="button" class="modal-close" aria-label="Close" @click="closeExamModal">
              <X :size="16" />
            </button>
          </header>

          <div class="exam-modal-body">
            <div class="exam-modal-layout">
              <div class="exam-modal-main">
                <section class="exam-modal-panel">
                  <div class="exam-panel-head">
                    <span class="exam-panel-kicker">Section 1</span>
                    <h5>Core Details <InfoTip text="Give the exam a clear identity and set the base workload." /></h5>
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
                    <h5>Question Set Mix <InfoTip text="Choose one or more sources. Students will receive items from the combined pool." /></h5>
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
                                  <small>{{ bank.total_items }} items<template v-if="seesEveryTeacher && bank.creator?.name"> &middot; By {{ bank.creator.name }}</template></small>
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
                    <h5>Availability And Rules <InfoTip text="Control when the exam opens and how many times students can take it." /></h5>
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
                            <strong>Shuffle questions <InfoTip text="Each attempt gets a randomized order when possible." /></strong>
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

                    <div class="field-stack" style="margin-top: 16px;">
                      <span class="field-label">At The End Time</span>
                      <div class="exam-policy-list" :class="{ 'is-disabled': !examForm.schedule_end_at }">
                        <label class="check-item exam-policy-item">
                          <input v-model="examForm.ends_at_close" type="radio" :value="false" :disabled="!examForm.schedule_end_at" />
                          <span>
                            <strong>Let them finish</strong>
                            <small>No new starts after the end time. Anyone who started before it keeps their full duration.</small>
                          </span>
                        </label>
                        <label class="check-item exam-policy-item">
                          <input v-model="examForm.ends_at_close" type="radio" :value="true" :disabled="!examForm.schedule_end_at" />
                          <span>
                            <strong>End for everyone</strong>
                            <small>Every attempt is submitted at the end time, even if its duration has not run out.</small>
                          </span>
                        </label>
                      </div>
                      <small v-if="!examForm.schedule_end_at" class="muted">
                        Set an End time in the Schedule Window to choose this. With no end time, everyone gets the full duration.
                      </small>
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
                  <template v-else>
                    <div class="exam-room-tools">
                      <span>{{ examForm.room_ids.length }} of {{ manageableRooms.length }} selected</span>
                      <button
                        type="button"
                        class="exam-room-tool"
                        :disabled="examForm.room_ids.length === manageableRooms.length"
                        @click="selectAllRooms"
                      >
                        Select all
                      </button>
                      <button
                        type="button"
                        class="exam-room-tool"
                        :disabled="examForm.room_ids.length === 0"
                        @click="examForm.room_ids = []"
                      >
                        Clear
                      </button>
                    </div>
                    <div class="check-grid exam-room-grid">
                      <label v-for="room in manageableRooms" :key="room.id" class="check-item exam-room-choice">
                        <input v-model="examForm.room_ids" type="checkbox" :value="room.id" />
                        <span class="exam-room-copy">
                          <strong>{{ room.name }}</strong>
                          <small>{{ room.code }}<template v-if="seesEveryTeacher && room.ownerName"> &middot; By {{ room.ownerName }}</template></small>
                        </span>
                      </label>
                    </div>
                  </template>
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
                    <small v-if="examForm.schedule_end_at">
                      {{
                        examForm.ends_at_close
                          ? 'Every attempt ends at the end time.'
                          : 'Students who start before the end time get their full duration.'
                      }}
                    </small>
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
            <!-- A save that fails has to say so here: the page's own banner
                 is underneath this form. -->
            <p v-if="examError" class="exam-modal-action-note is-error" role="alert">
              <AlertCircle :size="15" />
              <span>{{ examError }}</span>
            </p>
            <p v-else-if="needsMoreQuestions" class="exam-modal-action-note">
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
            Delete <strong>{{ selectedExam?.title }}</strong>? This cannot be undone.
          </p>

          <!-- The other way to retire it, and the only one once students
               have taken it. -->
          <p class="muted">
            <template v-if="examCanBeArchived">
              To keep the exam and its results, archive it instead. It leaves the current list of
              {{ examArchiveRoomsLabel }} and can be restored from there.
            </template>
            <template v-else>
              It is not in any room now, so there is nothing to archive.
            </template>
          </p>

          <!-- Nobody has started it, or it could not be deleted at all; but a
               class may still be waiting for it. -->
          <div v-if="examDeleteRooms.length > 0" class="feedback info" role="note">
            <AlertCircle :size="15" />
            <span>
              It is still {{ examDeleteIsUpcoming ? 'scheduled' : 'open' }} in
              <strong>{{ examDeleteRooms.join(', ') }}</strong>. Students there will no longer see it.
            </span>
          </div>

          <div v-if="examError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ examError }}</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="examSaving" @click="closeDeleteExamModal">Cancel</button>
            <button
              type="button"
              class="ghost-btn"
              :disabled="examSaving || !examCanBeArchived"
              :title="examCanBeArchived ? '' : 'This exam is not in any room'"
              @click="handleArchiveExam"
            >
              <Archive :size="15" />
              Archive
            </button>
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
import InfoTip from '@/components/InfoTip.vue'
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
import { estimatePageWidth, useElementWidth, usePhoneLayout } from '../composables/useViewport'
import { useExamsModule } from '../composables/useExamsModule'
import { beatAt, now } from '@/utils/clock'
import { vScrollHint } from '@/utils/scrollHint'
import { useAuthStore } from '@/store/auth.store'
import { isOwnedBy, ownersOf, overseesAll } from '@/utils/owners'
import OwnerFilter from '../shared/OwnerFilter.vue'

const route = useRoute()
const highlightedExamId = ref(null)

/** '' = All rooms; otherwise a room id, held as a string. */
const selectedRoomFilter = ref('')

// An administrator's list holds every teacher's exams.
const seesEveryTeacher = computed(() => overseesAll(useAuthStore().user))
const selectedOwnerFilter = ref('')
const examOwners = computed(() => ownersOf(exams.value))
const examSearch = ref('')

/*
 * The table needs 860px. Whether the list has that depends on the page's own
 * width, not the window's: a 1024px laptop with the sidebar open leaves it
 * 712px, and Edit and Delete ended up off the right-hand edge. So the page
 * is measured, and anything narrower gets the rows a phone gets.
 */
const EXAM_TABLE_MIN_WIDTH = 860
const isPhone = usePhoneLayout()
const pageRef = ref(null)
const pageWidth = useElementWidth(pageRef, estimatePageWidth())
const useRows = computed(() => (
  pageWidth.value > 0 ? pageWidth.value < EXAM_TABLE_MIN_WIDTH : isPhone.value
))

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
  dismissExamModal,
  openDeleteExamModal,
  closeDeleteExamModal,
  handleSaveExam,
  handleDeleteExam,
  handleArchiveExam,
} = useExamsModule()

// Archiving takes the exam off the rooms it is still in. One that is in none
// has nothing to archive.
const examCanBeArchived = computed(() => (selectedExam.value?.assigned_rooms ?? []).length > 0)

const examArchiveRoomsLabel = computed(() => {
  const names = (selectedExam.value?.assigned_rooms ?? []).map((room) => room.name).filter(Boolean)

  return names.length > 3 ? `its ${names.length} rooms` : names.join(', ')
})

// Rooms where the exam about to be deleted is still ahead of a class: it is
// assigned there and its window has not closed.
const examDeleteRooms = computed(() => {
  const exam = selectedExam.value
  if (!exam) return []

  const closesAt = exam.schedule_end_at ? new Date(exam.schedule_end_at).getTime() : null
  if (closesAt !== null && closesAt <= now()) return []

  return (exam.assigned_rooms ?? []).map((room) => room.name).filter(Boolean)
})

const examDeleteIsUpcoming = computed(() => {
  const opensAt = selectedExam.value?.schedule_start_at ?? selectedExam.value?.scheduled_at

  return Boolean(opensAt) && new Date(opensAt).getTime() > now()
})

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
    if (!isOwnedBy(exam, selectedOwnerFilter.value)) return false

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
  // The shared clock, and the moment this answer changes, so a row goes from
  // Upcoming to Open to Ended on its own as the time passes.
  const current = now()

  let dates = 'No schedule set'
  if (start && end) dates = `${formatCompactDate(start)} to ${formatCompactDate(end)}`
  else if (start) dates = `From ${formatCompactDate(start)}`
  else if (end) dates = `Until ${formatCompactDate(end)}`

  if (start && start.getTime() > current) {
    beatAt(start.getTime())
    return { tone: 'upcoming', label: 'Upcoming', dates }
  }

  if (end && end.getTime() < current) return { tone: 'ended', label: 'Ended', dates }
  if (end) beatAt(end.getTime())

  return { tone: 'open', label: 'Open', dates }
}

const openExamCount = computed(() => exams.value.filter((exam) => examWindow(exam).tone === 'open').length)

const unassignedExamCount = computed(() => (
  exams.value.filter((exam) => (exam.assigned_rooms ?? []).length === 0).length
))

// A row's second line: the size of the exam and where it is running.
function examRowMeta(exam) {
  const rooms = (exam.assigned_rooms ?? []).length
  const roomText = rooms === 0 ? 'No room' : `${rooms} ${rooms === 1 ? 'room' : 'rooms'}`

  const owner = seesEveryTeacher.value && exam.creator?.name ? ` · By ${exam.creator.name}` : ''

  return `${exam.total_items} questions · ${exam.duration_minutes} min · ${roomText}${owner}`
}

function examWindowPill(exam) {
  return { open: 'success', upcoming: 'navy', ended: 'neutral' }[examWindow(exam).tone]
}

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

// The footer's reminder only shows while it applies.
const needsMoreQuestions = computed(() => (
  selectedQuestionBankTotalItems.value < Number(examForm.total_items || 0)
))

const selectAllRooms = () => {
  examForm.room_ids = manageableRooms.value.map((room) => room.id)
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

/* ---------- Rows (wherever the table does not fit) ---------- */
.examlist-rows-card {
  border-radius: 20px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

/* Exam titles differ at their end ("... Mock Board Exam (AY 2015-2016)"),
   which is exactly the part the shared one-line ellipsis cuts off. Here the
   title wraps in full, and the row grows with it. */
.examlist-rows .directory-row-copy strong {
  white-space: normal;
  overflow: visible;
  overflow-wrap: anywhere;
  font-size: 14.5px;
  font-weight: 600;
  line-height: 1.3;
  color: #0b0d17;
}

.examlist-rows .directory-row-details.is-highlighted > summary {
  background: rgba(240, 208, 128, 0.22);
}

.examlist-rows .examrow-rooms {
  margin-top: 4px;
}

.examlist-row-actions {
  gap: 8px;
}

.examlist-row-actions .primary-btn {
  flex: 1 1 auto;
  max-width: 240px;
  height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
}

.examlist-row-actions .examrow-delete-btn {
  width: 44px;
  height: 44px;
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

  .examlist-rows-card {
    border-radius: 18px;
  }
}

/* Touch screens: a search field under 16px makes iOS zoom the whole page in
   when it is tapped, and a 25px room chip is hard to hit. */
@media (pointer: coarse) {
  .examlist-search-input {
    height: 44px;
    font-size: 16px;
  }

  .examlist-filter {
    height: 40px;
  }

  /* Only the chips that are links to a room; archived ones are labels. */
  .examlist-rows a.assignment-chip {
    min-height: 40px;
    padding-inline: 12px;
  }

  .exam-room-tool {
    height: 40px;
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

/* =====================================================================
   Exam form (the Create / Update dialog)
   Its base rules are in dashboard.css. This block comes after that file
   and settles what the shared dialog rules further down it had undone,
   plus the layout on small screens.
   ===================================================================== */

/* Head, body and footer are full-width bands. The shared .modal-card
   padding put a white frame around all three, 48px of a phone's width. */
.exam-modal-card {
  padding: 0;
  gap: 0;
  max-height: calc(100vh - 24px);
  max-height: calc(100dvh - 24px);
}

.exam-modal-body {
  overscroll-behavior: contain;
}

/* Below 1200px the form is one column. The summary follows the sections it
   summarises, next to the Save button, instead of opening the form with a
   screenful of zeroes above the first field. */
.exam-modal-side {
  order: 0;
}

/* Gold on the navy card. It was navy on navy. */
.exam-summary-kicker {
  color: var(--lnu-gold-light);
}

.exam-summary-stat strong {
  line-height: 1.2;
}

/* ---------- Rooms ---------- */
.exam-room-tools {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.exam-room-tools span {
  margin-right: auto;
}

.exam-room-tool {
  height: 36px;
  padding: 0 12px;
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  background: var(--surface);
  color: var(--lnu-navy);
  font-size: 13px;
  font-weight: 600;
}

.exam-room-tool:hover:not(:disabled) {
  border-color: rgba(26, 35, 126, 0.34);
  background: #f2f5fd;
}

.exam-room-tool:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.exam-room-tool:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

/* Rows are sized by their content (max-content), so a list longer than its
   window scrolls instead of squeezing the rows into each other. */
.exam-room-grid {
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  grid-auto-rows: max-content;
  align-content: start;
  max-height: 268px;
}

.exam-room-choice {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 52px;
  cursor: pointer;
}

.exam-room-choice input {
  flex-shrink: 0;
  width: 18px;
  height: 18px;
  margin: 0;
}

.exam-room-copy {
  min-width: 0;
}

.exam-room-copy strong,
.exam-room-copy small {
  overflow-wrap: anywhere;
}

.exam-room-choice:has(input:checked) {
  border-color: rgba(26, 35, 126, 0.5);
  background: rgba(26, 35, 126, 0.05);
}

.exam-room-choice:has(input:focus-visible) {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

/* ---------- Footer ---------- */
.exam-modal-actions {
  gap: 12px;
}

.exam-modal-action-note.is-error {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  color: var(--lnu-danger);
  font-weight: 500;
}

.exam-modal-action-note.is-error svg {
  flex-shrink: 0;
  margin-top: 2px;
}

.exam-modal-action-buttons {
  width: auto;
  margin-left: auto;
  display: inline-flex;
  flex-wrap: nowrap;
}

.exam-modal-action-buttons .primary-btn,
.exam-modal-action-buttons .ghost-btn {
  width: auto;
}

/* ---------- Up to 900px: one scroll, not a box scrolling inside the form ---------- */
@media (max-width: 900px) {
  .exam-room-grid {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    max-height: none;
    overflow: visible;
    padding: 0;
    border: 0;
    background: none;
  }
}

/* ---------- Phones: the form is the screen ---------- */
@media (max-width: 640px) {
  .exam-modal-backdrop {
    padding: 0;
  }

  /* The visible height (dvh): 100vh runs under a phone browser's toolbar
     and took the Save button with it. */
  .exam-modal-card {
    width: 100%;
    height: 100vh;
    height: 100dvh;
    max-height: none;
    margin: 0;
    border: 0;
    border-radius: 0;
  }

  /* One line, title and Close. The head and footer together used to take
     295px of an 844px screen and left the fields a 470px window. */
  .exam-modal-head {
    gap: 8px;
    padding: 8px 8px 8px 16px;
  }

  .exam-modal-top-pills,
  .exam-modal-title-wrap h4 {
    font-size: 18px;
    line-height: 1.3;
  }

  .exam-modal-body {
    padding: 12px;
    gap: 12px;
  }

  .exam-modal-main {
    gap: 12px;
  }

  .exam-modal-panel,
  .exam-modal-summary {
    padding: 14px;
    border-radius: 16px;
  }

  .exam-panel-head h5 {
    font-size: 16px;
  }

  /* Items and Minutes are two short numbers: side by side. */
  .exam-meta-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .exam-meta-row .field-stack {
    width: auto;
  }

  .exam-modal-actions {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
    padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px));
  }

  .exam-modal-action-note {
    max-width: none;
  }

  /* Side by side: two stacked full-width buttons took 100px on their own. */
  .exam-modal-action-buttons {
    width: 100%;
    margin-left: 0;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.6fr);
    gap: 8px;
  }

  .exam-modal-action-buttons .primary-btn,
  .exam-modal-action-buttons .ghost-btn {
    width: 100%;
    justify-content: center;
  }
}

.exam-policy-list.is-disabled {
  opacity: 0.55;
}

.exam-policy-list.is-disabled .exam-policy-item {
  cursor: not-allowed;
}
</style>
