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
      <!-- Find an exam: by name, by where it is in its window, or by the room
           it is assigned to; and the order to list them in -->
      <div class="examlist-toolbar">
        <div class="examlist-toolbar-line">
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

          <label class="examlist-sort">
            <ArrowUpDown :size="15" class="examlist-sort-icon" aria-hidden="true" />
            <select v-model="examSortChoice" class="examlist-sort-select" aria-label="Sort exams">
              <option v-for="option in EXAM_SORT_CHOICES" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
        </div>

        <div class="examlist-toolbar-line">
          <OwnerFilter v-if="seesEveryTeacher" v-model="selectedOwnerFilter" :owners="examOwners" label="Exams by" />
          <RoomFilter
            v-model="selectedRoomFilter"
            :rooms="roomFilterRooms"
            :total-count="roomFilterTotal"
            :group-by-owner="seesEveryTeacher && selectedOwnerFilter === ''"
          />
        </div>

        <div v-scroll-hint class="examlist-filters" role="group" aria-label="Filter by status">
          <span class="examlist-filters-label" aria-hidden="true">Status</span>
          <button
            v-for="option in EXAM_STATUS_FILTERS"
            :key="option.value || 'all'"
            type="button"
            class="examlist-filter"
            :class="{ active: selectedStatusFilter === option.value }"
            :aria-pressed="selectedStatusFilter === option.value"
            @click="selectedStatusFilter = option.value"
          >
            {{ option.label }}
            <span class="examlist-filter-count">{{ examStatusCounts[option.value] }}</span>
          </button>
        </div>
      </div>

      <!-- Exams exist, just none that match -- a different problem from
           having no exams at all, so it gets its own way out. -->
      <div v-if="filteredExams.length === 0" class="examlist-state">
        <span class="examlist-state-icon"><Search :size="22" /></span>
        <strong>{{ examSearch || selectedStatusFilter ? 'No exams match' : `No exams in ${selectedRoomName}` }}</strong>
        <p>
          {{
            examSearch || selectedStatusFilter
              ? 'Try a different search, status or room.'
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
                <th scope="col" :aria-sort="examSortAria('title')">
                  <button type="button" class="examlist-sort-btn" @click="toggleExamSort('title')">
                    Exam
                    <span class="examlist-sort-arrow" aria-hidden="true">{{ examSortArrow('title') }}</span>
                  </button>
                </th>
                <th scope="col" class="is-number" :aria-sort="examSortAria('items')">
                  <button type="button" class="examlist-sort-btn" @click="toggleExamSort('items')">
                    Questions
                    <span class="examlist-sort-arrow" aria-hidden="true">{{ examSortArrow('items') }}</span>
                  </button>
                </th>
                <th scope="col" class="is-number" :aria-sort="examSortAria('minutes')">
                  <button type="button" class="examlist-sort-btn" @click="toggleExamSort('minutes')">
                    Minutes
                    <span class="examlist-sort-arrow" aria-hidden="true">{{ examSortArrow('minutes') }}</span>
                  </button>
                </th>
                <th scope="col">Rooms</th>
                <th scope="col" :aria-sort="examSortAria('schedule')">
                  <button type="button" class="examlist-sort-btn" @click="toggleExamSort('schedule')">
                    Schedule
                    <span class="examlist-sort-arrow" aria-hidden="true">{{ examSortArrow('schedule') }}</span>
                  </button>
                </th>
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
                  <!-- Each chip opens that room's page directly. Two show; the
                       rest wait behind "+N more", or a whole year level's six
                       sections made every row three lines taller. -->
                  <div v-if="exam.assigned_rooms?.length > 0 || exam.archived_rooms?.length > 0" class="examrow-rooms">
                    <router-link
                      v-for="room in roomChips(exam).assigned"
                      :key="`room-${room.id}`"
                      class="assignment-chip"
                      :to="{ name: 'dashboard-room-management', query: { room: room.id } }"
                      :title="`Open ${room.name}`"
                    >
                      <DoorOpen :size="12" />
                      {{ room.name }}
                    </router-link>
                    <span
                      v-for="room in roomChips(exam).archived"
                      :key="`archived-room-${room.id}`"
                      class="assignment-chip is-archived"
                      title="Archived from this room"
                    >
                      <Archive :size="12" />
                      {{ room.name }}
                    </span>
                    <button
                      v-if="roomChips(exam).hidden > 0 || expandedRoomExams.has(exam.id)"
                      type="button"
                      class="assignment-chip examrow-rooms-more"
                      :aria-expanded="expandedRoomExams.has(exam.id)"
                      @click="toggleRoomChips(exam.id)"
                    >
                      {{ expandedRoomExams.has(exam.id) ? 'Show less' : `+${roomChips(exam).hidden} more` }}
                    </button>
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
            <p v-else-if="selectedQuestionBanks.length === 0" class="exam-modal-action-note">
              Choose at least one question set from the Library before saving.
            </p>
            <p v-else-if="needsMoreQuestions" class="exam-modal-action-note">
              Choose enough question sets to cover the requested item count before saving.
            </p>
            <div class="exam-modal-action-buttons">
              <button type="button" class="ghost-btn" :disabled="examSaving" @click="closeExamModal">Cancel</button>
              <button
                type="button"
                class="primary-btn"
                :disabled="examSaving || !examForm.title.trim() || examForm.total_items < 1 || examForm.duration_minutes < 1 || needsMoreQuestions"
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
  ArrowUpDown,
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
import RoomFilter from '../shared/RoomFilter.vue'

const route = useRoute()
const highlightedExamId = ref(null)

/*
 * How the list is narrowed and ordered, kept on this device so the page
 * opens the way it was left. Only conveniences: an unreadable or missing
 * entry just means the defaults.
 */
const EXAM_LIST_STORAGE_KEY = 'blis-exam-list'

function readSavedExamList() {
  try {
    return JSON.parse(localStorage.getItem(EXAM_LIST_STORAGE_KEY) ?? '{}') ?? {}
  } catch {
    return {}
  }
}

const savedExamList = readSavedExamList()

/** '' = All rooms; otherwise a room id, held as a string. */
const selectedRoomFilter = ref(typeof savedExamList.room === 'string' ? savedExamList.room : '')

// Where an exam is in its window (examWindow() below), or that it is in no room.
const EXAM_STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'open', label: 'Open now' },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'ended', label: 'Ended' },
  { value: 'unassigned', label: 'Not in a room' },
]
const selectedStatusFilter = ref(
  EXAM_STATUS_FILTERS.some((option) => option.value === savedExamList.status) ? savedExamList.status : '',
)

// Newest created first until another order is picked: the order exams come
// from the server. A heading sorts by its column; the menu offers every
// order, direction included, and is the only way to sort on a phone.
const EXAM_SORT_CHOICES = [
  { value: 'created:desc', label: 'Newest first' },
  { value: 'created:asc', label: 'Oldest first' },
  { value: 'schedule:desc', label: 'Schedule: latest first' },
  { value: 'schedule:asc', label: 'Schedule: earliest first' },
  { value: 'title:asc', label: 'Name: A to Z' },
  { value: 'title:desc', label: 'Name: Z to A' },
  { value: 'items:desc', label: 'Most questions' },
  { value: 'items:asc', label: 'Fewest questions' },
  { value: 'minutes:desc', label: 'Longest' },
  { value: 'minutes:asc', label: 'Shortest' },
]
// A heading's first click: names A to Z, the rest largest or latest first.
const EXAM_SORT_FIRST_DIRECTION = { title: 'asc', items: 'desc', minutes: 'desc', schedule: 'desc', created: 'desc' }
const examSortChoice = ref(
  EXAM_SORT_CHOICES.some((option) => option.value === savedExamList.sort) ? savedExamList.sort : 'created:desc',
)
const examSort = computed(() => {
  const [key, direction] = examSortChoice.value.split(':')
  return { key, direction }
})

function toggleExamSort(key) {
  const { key: current, direction } = examSort.value
  const next = current === key
    ? (direction === 'asc' ? 'desc' : 'asc')
    : EXAM_SORT_FIRST_DIRECTION[key]
  examSortChoice.value = `${key}:${next}`
}

function examSortArrow(key) {
  if (examSort.value.key !== key) return ''
  return examSort.value.direction === 'asc' ? '▲' : '▼'
}

function examSortAria(key) {
  if (examSort.value.key !== key) return 'none'
  return examSort.value.direction === 'asc' ? 'ascending' : 'descending'
}

watch([selectedRoomFilter, selectedStatusFilter, examSortChoice], () => {
  try {
    localStorage.setItem(EXAM_LIST_STORAGE_KEY, JSON.stringify({
      room: selectedRoomFilter.value,
      status: selectedStatusFilter.value,
      sort: examSortChoice.value,
    }))
  } catch {
    // Storage refused (a private window): the choices still apply until the page is left.
  }
})

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
function matchesExamFilters(exam, { ignoreRoom = false } = {}) {
  if (!isOwnedBy(exam, selectedOwnerFilter.value)) return false

  if (!ignoreRoom && selectedRoomFilter.value !== ''
    && !(exam.assigned_rooms ?? []).some((room) => String(room.id) === selectedRoomFilter.value)) {
    return false
  }

  const needle = examSearch.value.toLowerCase()
  if (!needle) return true

  return [exam.title, exam.subject, examQuestionBankSubject(exam)]
    .some((value) => String(value ?? '').toLowerCase().includes(needle))
}

function examStatusMatches(exam, status) {
  if (status === '') return true
  if (status === 'unassigned') return (exam.assigned_rooms ?? []).length === 0
  return examWindow(exam).tone === status
}

// Each status chip counts what choosing it would show, under the other filters.
const examStatusCounts = computed(() => {
  const narrowed = exams.value.filter(matchesExamFilters)

  return Object.fromEntries(EXAM_STATUS_FILTERS.map(({ value }) => [
    value,
    narrowed.filter((exam) => examStatusMatches(exam, value)).length,
  ]))
})

const timeOf = (value) => parseDateTime(value)?.getTime() ?? null

// What each order compares; null (an exam with no schedule) always goes last.
const EXAM_SORT_VALUES = {
  created: (exam) => timeOf(exam.created_at),
  schedule: (exam) => timeOf(exam.schedule_start_at ?? exam.scheduled_at),
  title: (exam) => String(exam.title ?? ''),
  items: (exam) => Number(exam.total_items ?? 0),
  minutes: (exam) => Number(exam.duration_minutes ?? 0),
}

function compareExams(left, right) {
  const { key, direction } = examSort.value
  const valueOf = EXAM_SORT_VALUES[key]
  const a = valueOf(left)
  const b = valueOf(right)

  if (a === null || b === null) {
    if (a !== b) return a === null ? 1 : -1
  } else {
    const order = typeof a === 'string'
      ? a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
      : a - b
    if (order !== 0) return direction === 'asc' ? order : -order
  }

  // Equal: newest created first, as the list is by default.
  return (timeOf(right.created_at) ?? 0) - (timeOf(left.created_at) ?? 0) || right.id - left.id
}

const filteredExams = computed(() => (
  exams.value
    .filter((exam) => matchesExamFilters(exam) && examStatusMatches(exam, selectedStatusFilter.value))
    .sort(compareExams)
))

// The room menu: the chosen teacher's rooms (all of them for "All teachers"),
// each counting what picking it would show under the other filters.
const examsForRoomCounts = computed(() => (
  exams.value.filter((exam) => (
    matchesExamFilters(exam, { ignoreRoom: true }) && examStatusMatches(exam, selectedStatusFilter.value)
  ))
))

const roomFilterRooms = computed(() => {
  const counts = new Map()
  for (const exam of examsForRoomCounts.value) {
    for (const room of exam.assigned_rooms ?? []) counts.set(String(room.id), (counts.get(String(room.id)) ?? 0) + 1)
  }

  return manageableRooms.value
    .filter((room) => selectedOwnerFilter.value === '' || String(room.ownerId) === selectedOwnerFilter.value
      || String(room.id) === selectedRoomFilter.value)
    .map((room) => ({ ...room, count: counts.get(String(room.id)) ?? 0 }))
})

const roomFilterTotal = computed(() => examsForRoomCounts.value.length)

// The table's Rooms column: two chips, the filtered room among them, and
// "+N more" for the rest, which opens them in place.
const ROOM_CHIPS_SHOWN = 2
const expandedRoomExams = ref(new Set())

function toggleRoomChips(examId) {
  const next = new Set(expandedRoomExams.value)
  if (next.has(examId)) next.delete(examId)
  else next.add(examId)
  expandedRoomExams.value = next
}

function roomChips(exam) {
  const picked = selectedRoomFilter.value
  const assigned = [...(exam.assigned_rooms ?? [])]
    .sort((left, right) => (String(right.id) === picked) - (String(left.id) === picked))
  const archived = exam.archived_rooms ?? []

  if (expandedRoomExams.value.has(exam.id)) return { assigned, archived, hidden: 0 }

  const shownAssigned = assigned.slice(0, ROOM_CHIPS_SHOWN)
  const shownArchived = archived.slice(0, ROOM_CHIPS_SHOWN - shownAssigned.length)
  // One left over is shown rather than hidden behind "+1 more".
  if (assigned.length + archived.length === ROOM_CHIPS_SHOWN + 1) return { assigned, archived, hidden: 0 }

  return {
    assigned: shownAssigned,
    archived: shownArchived,
    hidden: assigned.length + archived.length - shownAssigned.length - shownArchived.length,
  }
}

function clearExamFilters() {
  examSearch.value = ''
  selectedRoomFilter.value = ''
  selectedStatusFilter.value = ''
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
    selectedStatusFilter.value = ''
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

<style scoped src="./examsView.css"></style>
