<template>
  <section class="room-view">
    <div v-if="roomMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ roomMessage }}</span>
    </div>
    <div v-if="roomError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ roomError }}</span>
    </div>

    <div v-if="roomLoading && rooms.length === 0" class="surface-card rooms-empty-card">
      <RefreshCw :size="34" class="spin-soft" />
      <h4>Loading rooms</h4>
      <p>Please wait while we fetch your room list.</p>
    </div>

    <div v-else-if="rooms.length === 0" class="surface-card rooms-empty-card">
      <DoorOpen :size="42" />
      <h4>Join a Room</h4>
      <p>Use your room code to join and view assigned exams and enrolled classmates.</p>
      <button class="primary-btn rooms-join-btn" :disabled="roomLoading" @click="openJoinRoomModal">
        <DoorOpen :size="16" />
        Join Room
      </button>
    </div>

    <div v-else class="rooms-grid">
      <Teleport to="#layout-topbar-actions" defer :disabled="!isCompact">
        <button
          v-if="isCompact"
          type="button"
          class="appbar-icon-action"
          aria-label="Join room"
          :disabled="roomLoading"
          @click="openJoinRoomModal"
        >
          <DoorOpen :size="20" />
        </button>
      </Teleport>

      <aside class="surface-card rooms-list-card">
        <div class="rooms-list-head">
          <span class="rooms-list-label">My Rooms</span>
          <span class="rooms-count-badge">{{ rooms.length }}</span>
        </div>

        <button type="button" class="primary-btn rooms-join-btn" :disabled="roomLoading" @click="openJoinRoomModal">
          <DoorOpen :size="15" />
          Join Room
        </button>

        <label class="rooms-search">
          <Search :size="14" />
          <input v-model="roomSearchQuery" type="text" placeholder="Search rooms..." />
        </label>

        <div class="rooms-list">
          <button
            v-for="room in filteredRooms"
            :key="room.id"
            type="button"
            class="rooms-list-item"
            :class="{ active: selectedRoomId === room.id }"
            @click="selectRoom(room.id)"
          >
            <div class="rooms-list-item-head">
              <strong>{{ room.name }}</strong>
              <ChevronRight :size="15" class="rooms-list-chevron" />
            </div>
            <p class="rooms-list-code">CODE: {{ room.code }}</p>
            <div class="rooms-list-meta">
              <span><Users :size="12" />{{ room.members_count ?? 0 }} members</span>
            </div>
            <p v-if="room.creator?.name" class="rooms-list-by">By {{ room.creator.name }}</p>
          </button>

          <p v-if="filteredRooms.length === 0" class="muted rooms-list-empty">No rooms match "{{ roomSearchQuery }}".</p>
        </div>
      </aside>

      <section class="room-detail-column">
        <div v-if="roomDetailsLoading" class="surface-card rooms-detail-loading">
          <RefreshCw :size="18" class="spin-soft" />
          <span>Loading room details...</span>
        </div>

        <template v-else-if="selectedRoom">
          <header class="room-banner">
            <div class="room-banner-copy">
              <h4>{{ selectedRoom.name }}</h4>
              <div class="room-banner-meta">
                <span class="room-banner-pill"><DoorOpen :size="12" />Room Code: {{ selectedRoom.code }}</span>
                <span class="room-banner-pill"><Users :size="12" />{{ selectedRoom.members_count ?? selectedRoom.members.length }} members active in this room</span>
              </div>
            </div>
            <button class="danger-btn room-banner-leave-btn" :disabled="roomLoading || roomDetailsLoading" @click="openLeaveRoomModal">
              <LogOut :size="14" />
              Leave Room
            </button>
          </header>

          <article class="surface-card rooms-exams-card">
            <div class="rooms-exams-head">
              <div>
                <h5>Current Exams</h5>
                <p class="muted">Manage and review your active exam sessions</p>
              </div>
              <button
                v-if="selectedRoomArchivedExams.length > 0"
                type="button"
                class="rooms-link-btn"
                @click="showArchivedExams = !showArchivedExams"
              >
                {{ showArchivedExams ? 'Hide archived' : 'View all archived' }}
              </button>
            </div>

            <p v-if="selectedRoomActiveExams.length === 0" class="muted empty-detail">No active exams assigned to this room right now.</p>
            <div v-else class="rooms-exam-list">
              <article
                v-for="exam in selectedRoomActiveExams"
                :key="exam.id"
                class="rooms-exam-row"
                :class="{ locked: !canStudentOpenExam(exam) }"
              >
                <div class="rooms-exam-row-head">
                  <strong class="rooms-exam-title">{{ exam.title }}</strong>
                  <button
                    type="button"
                    class="rooms-exam-btn"
                    :class="{
                      'is-resume': isStudentExamInProgress(exam),
                      'is-retake': isStudentExamCompleted(exam) && !isStudentExamRetakeLimitReached(exam),
                      'is-review': isStudentExamRetakeLimitReached(exam),
                    }"
                    :disabled="!canStudentOpenExam(exam)"
                    @click="openExamSimulation(exam)"
                  >
                    {{ studentExamActionLabel(exam) }}
                  </button>
                </div>

                <div class="rooms-exam-row-foot">
                  <div class="rooms-exam-meta">
                    <span><BookOpen :size="13" />{{ exam.total_items }} items</span>
                    <span><Clock3 :size="13" />{{ exam.duration_minutes }} mins</span>
                  </div>

                  <div v-if="isStudentExamEnded(exam)" class="rooms-exam-window">
                    <span class="rooms-exam-window-label">Window Ended</span>
                    <span class="rooms-exam-window-date">
                      {{ formatLongDate(examScheduleEnd(exam)) }} at {{ formatTimeOnly(examScheduleEnd(exam)) }}
                    </span>
                  </div>
                  <p v-else class="rooms-exam-window-fallback">{{ studentExamAvailabilityText(exam) }}</p>
                </div>
              </article>
            </div>

            <div v-if="showArchivedExams && selectedRoomArchivedExams.length > 0" class="rooms-exam-list archived">
              <article
                v-for="exam in selectedRoomArchivedExams"
                :key="`archived-${exam.id}`"
                class="rooms-exam-row archived"
                :class="{ locked: !archivedExamAction(exam) }"
              >
                <div class="rooms-exam-row-head">
                  <strong class="rooms-exam-title">{{ exam.title }}</strong>
                  <!-- An archived exam takes no new attempts. -->
                  <button
                    v-if="archivedExamAction(exam)"
                    type="button"
                    class="rooms-exam-btn"
                    @click="openArchivedExam(exam)"
                  >
                    {{ archivedExamActionLabel(exam) }}
                  </button>
                </div>

                <div class="rooms-exam-row-foot">
                  <div class="rooms-exam-meta">
                    <span><BookOpen :size="13" />{{ exam.total_items }} items</span>
                    <span><Clock3 :size="13" />{{ exam.duration_minutes }} mins</span>
                  </div>

                  <div v-if="isStudentExamEnded(exam)" class="rooms-exam-window">
                    <span class="rooms-exam-window-label">Window Ended</span>
                    <span class="rooms-exam-window-date">
                      {{ formatLongDate(examScheduleEnd(exam)) }} at {{ formatTimeOnly(examScheduleEnd(exam)) }}
                    </span>
                  </div>
                  <p v-else class="rooms-exam-window-fallback">{{ archivedExamStatusText(exam) }}</p>
                </div>
              </article>
            </div>
          </article>
        </template>

        <div v-else class="surface-card rooms-detail-empty">
          <DoorOpen :size="30" />
          <h4>Select a room</h4>
          <p>Choose a room from the list to view members and assigned exams.</p>
        </div>
      </section>

      <aside v-if="selectedRoom" class="room-side-column">
        <article class="surface-card rooms-roster-card">
          <div class="rooms-roster-head">
            <Users :size="15" />
            <h5>Class List</h5>
          </div>

          <div class="rooms-roster-tags">
            <span class="rooms-tag success">{{ selectedRoom.members_count ?? selectedRoom.members.length }} current</span>
            <span class="rooms-tag neutral">{{ selectedRoomYearSummary.label }}</span>
          </div>

          <p class="muted rooms-roster-copy">{{ selectedRoomRosterCopy }}</p>

          <p v-if="selectedRoomCurrentMembers.length === 0" class="muted empty-detail">No members enrolled yet.</p>

          <div v-else class="rooms-roster-list">
            <div v-for="member in selectedRoomCurrentMembers" :key="member.id" class="rooms-roster-item">
              <span class="rooms-roster-avatar"><CircleUserRound :size="22" /></span>
              <div class="rooms-roster-item-copy">
                <strong>{{ member.name }}</strong>
                <p v-if="member.student_id">Student ID: {{ member.student_id }}</p>
                <p v-else>{{ member.email }}</p>
              </div>
              <span v-if="selectedRoomYearSummary.is_mixed" class="rooms-tag neutral small">
                {{ member.year_level_label || yearLevelLabel(member.year_level) }}
              </span>
            </div>
          </div>
        </article>

        <article class="rooms-summary-card">
          <p class="rooms-summary-title">Quick Summary</p>

          <div class="rooms-summary-row">
            <span>Completion Rate</span>
            <strong>{{ selectedRoomExamSummary.completionPercent }}%</strong>
          </div>
          <div class="rooms-summary-bar">
            <div class="rooms-summary-bar-fill" :style="{ width: `${selectedRoomExamSummary.completionPercent}%` }" />
          </div>

          <div class="rooms-summary-stats">
            <div class="rooms-summary-stat">
              <strong>{{ selectedRoomExamSummary.totalCount }}</strong>
              <span>Exams</span>
            </div>
            <div class="rooms-summary-stat">
              <strong>{{ selectedRoomExamSummary.completedCount }}</strong>
              <span>Completed</span>
            </div>
          </div>
        </article>
      </aside>
    </div>

    <teleport to="body">
      <div v-if="showJoinRoomModal" class="modal-backdrop" @click.self="closeJoinRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Join Room</h4>
            <button type="button" class="modal-close" @click="closeJoinRoomModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Enter the room code provided by your examiner.</p>

          <label class="field-stack">
            <span class="field-label">Room code</span>
            <input
              v-model="joinCode"
              type="text"
              class="text-input code"
              maxlength="12"
              placeholder="Enter room code"
              @input="joinCode = joinCode.toUpperCase()"
            />
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeJoinRoomModal">Cancel</button>
            <button type="button" class="primary-btn" :disabled="roomLoading || !joinCode.trim()" @click="handleJoinRoom">
              <DoorOpen :size="16" />
              Join
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showLeaveRoomModal" class="modal-backdrop" @click.self="closeLeaveRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Leave Room</h4>
            <button type="button" class="modal-close" @click="closeLeaveRoomModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            Leave <strong>{{ selectedRoom?.name }}</strong> (<code>{{ selectedRoom?.code }}</code>)?
          </p>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeLeaveRoomModal">Cancel</button>
            <button type="button" class="danger-btn" :disabled="roomLoading" @click="handleLeaveRoom">
              <LogOut :size="16" />
              Leave Room
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showExamSimulationModal" class="exam-attempt-backdrop">
        <div class="exam-attempt-shell" role="dialog" aria-modal="true" aria-labelledby="exam-attempt-title">
          <header class="exam-attempt-head">
            <div class="exam-head-copy">
              <h3 id="exam-attempt-title">{{ selectedStudentExam?.title || 'Exam Attempt' }}</h3>
              <p>
                {{ selectedRoom?.name || 'Unknown Room' }}
                <span v-if="selectedRoom?.code">· {{ selectedRoom.code }}</span>
                <span v-if="selectedStudentExam">· {{ examDeliveryModeLabel(selectedStudentExam.delivery_mode) }}</span>
              </p>
            </div>

            <div class="exam-head-meta">
              <div v-if="studentExamAttempt && !isStudentExamSubmitted" class="exam-progress">
                <div class="exam-progress-top">
                  <span class="exam-progress-label">Answered</span>
                  <span class="exam-progress-value">
                    {{ studentExamAttempt.answered_count }}<em>/{{ studentExamAttempt.total_items }}</em>
                  </span>
                </div>
                <div
                  class="exam-progress-track"
                  role="progressbar"
                  :aria-valuenow="examProgressPercent"
                  aria-valuemin="0"
                  aria-valuemax="100"
                  aria-label="Questions answered"
                >
                  <div class="exam-progress-fill" :style="{ width: `${examProgressPercent}%` }" />
                </div>
              </div>

              <div
                v-if="studentExamAttempt && !isStudentExamSubmitted && studentExamRemainingSeconds !== null"
                class="exam-timer"
                :class="examTimerTone"
                role="timer"
                :aria-label="`Time remaining: ${formatRemainingDuration(studentExamRemainingSeconds)}`"
              >
                <Clock3 :size="15" />
                <span class="exam-timer-value">{{ formatRemainingDuration(studentExamRemainingSeconds) }}</span>
              </div>

              <div v-if="studentExamAttempt && isStudentExamSubmitted" class="exam-head-score">
                <span class="pill success">Score: {{ Number(studentExamAttempt.score_percent ?? 0).toFixed(2) }}%</span>
                <span class="pill neutral">
                  {{ Number(studentExamAttempt.correct_answers ?? 0) }}/{{ Number(studentExamAttempt.total_items ?? 0) }} correct
                </span>
              </div>

              <button
                type="button"
                class="ghost-btn"
                :disabled="studentExamSubmitting"
                @click="handleExamAttemptCloseClick"
              >
                {{ isStudentExamSubmitted ? 'Close' : 'Exit' }}
              </button>
            </div>
          </header>

          <div v-if="studentExamError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ studentExamError }}</span>
          </div>

          <div v-if="studentExamLoading" class="room-detail-loading exam-attempt-loading">
            <RefreshCw :size="16" class="spin-soft" />
            <span>Preparing exam attempt...</span>
          </div>

          <div
            v-else-if="studentExamAttempt"
            class="exam-attempt-layout"
            :class="{
              'is-results-view': isStudentExamResultSummaryVisible,
              'is-list-hidden': examAttemptSidebarCollapsed,
            }"
          >
            <!-- One question list, two containers: the left-hand column from
                 901px up, and a bottom sheet on a phone, where a column that
                 had to share the screen with the question had no room left
                 to show the numbers. -->
            <component
              :is="isExamMobileViewport ? BottomSheet : 'aside'"
              v-if="!isStudentExamResultSummaryVisible"
              v-bind="examQuestionListContainerProps"
              @update:open="setExamQuestionListOpen"
            >
              <div class="exam-status-legend">
                <template v-if="!isStudentExamSubmitted">
                  <span class="legend-item"><i class="legend-dot current" /> Current</span>
                  <span class="legend-item"><i class="legend-dot answered" /> Answered</span>
                  <span class="legend-item"><i class="legend-dot blank" /> Blank</span>
                  <span class="legend-item"><i class="legend-ribbon" /> Bookmarked</span>
                </template>
                <template v-else>
                  <span class="legend-item"><i class="legend-dot current-outline" /> Current</span>
                  <span class="legend-item"><i class="legend-dot answered" /> Answered</span>
                  <span class="legend-item"><i class="legend-dot missed" /> Not answered</span>
                  <span class="legend-item"><i class="legend-ribbon" /> Bookmarked</span>
                </template>
              </div>

              <div v-if="isStudentOpenNavigationMode || isStudentExamSubmitted" class="exam-question-jump immersive">
                <button
                  v-for="(question, index) in studentExamQuestions"
                  :key="question.question_id"
                  type="button"
                  class="exam-jump-btn"
                  :class="questionPaletteClass(question, index)"
                  :disabled="studentExamSaving || studentExamSubmitting || studentExamBookmarking || (!isStudentOpenNavigationMode && !isStudentExamSubmitted)"
                  @click="pickStudentExamQuestionFromList(index)"
                >
                  {{ question.item_number }}
                </button>
              </div>
            </component>

            <section class="exam-attempt-main">
              <div v-if="isStudentExamResultSummaryVisible" class="room-detail-loading exam-attempt-loading">
                <RefreshCw :size="16" class="spin-soft" />
                <span>Taking you to your results...</span>
              </div>

              <article v-else-if="currentStudentExamQuestion" class="exam-attempt-card immersive">
                <header class="surface-head">
                  <h4>Question {{ currentStudentExamQuestion.item_number }}</h4>
                  <span class="pill neutral">{{ currentStudentExamQuestion.question_type.replace('_', ' ') }}</span>
                </header>

                <div v-if="currentQuestionStem.numberedItems.length > 0" class="exam-question-stem">
                  <p class="exam-attempt-question-text">{{ currentQuestionStem.leadText }}</p>
                  <ol class="exam-question-list">
                    <li
                      v-for="(itemText, itemIndex) in currentQuestionStem.numberedItems"
                      :key="`stem-item-${currentStudentExamQuestion.question_id}-${itemIndex}`"
                    >
                      {{ itemText }}
                    </li>
                  </ol>
                </div>

                <p v-else class="exam-attempt-question-text">{{ currentStudentExamQuestion.question_text }}</p>

                <div v-if="currentStudentExamQuestion.question_type === 'open_ended'" class="field-stack">
                  <textarea
                    v-model="studentAnswerDraft.answer_text"
                    class="text-input textarea-input"
                    rows="5"
                    placeholder="Type your answer here..."
                    :disabled="isCurrentQuestionInputLocked"
                    @blur="handleStudentOpenEndedBlur"
                  />
                </div>

                <div v-else class="exam-option-grid">
                  <label
                    v-for="option in currentStudentExamQuestion.options"
                    :key="option.id"
                    class="exam-option-card"
                    :class="examOptionCardClass(option)"
                  >
                    <input
                      class="exam-option-input"
                      :checked="studentAnswerDraft.selected_option_id === option.id"
                      type="radio"
                      :name="`exam-question-${currentStudentExamQuestion.question_id}`"
                      :disabled="isCurrentQuestionInputLocked"
                      @change="handleStudentOptionSelect(option.id)"
                    />
                    <span class="exam-option-letter" aria-hidden="true">{{ option.label }}</span>
                    <span class="exam-option-text">{{ option.text }}</span>
                  </label>
                </div>

                <div
                  v-if="isStudentExamSubmitted"
                  class="exam-question-review-panel"
                  :class="`is-${studentQuestionReviewStatus(currentStudentExamQuestion)}`"
                >
                  <div class="exam-question-review-copy">
                    <strong>{{ studentQuestionReviewLabel(currentStudentExamQuestion) }}</strong>
                    <p>{{ studentQuestionReviewMessage(currentStudentExamQuestion) }}</p>
                  </div>
                  <p
                    v-if="studentQuestionCorrectAnswerText(currentStudentExamQuestion)"
                    class="exam-question-review-answer"
                  >
                    Correct answer:
                    <span>{{ studentQuestionCorrectAnswerText(currentStudentExamQuestion) }}</span>
                  </p>
                </div>
              </article>

              <div v-if="!isStudentExamResultSummaryVisible" class="exam-attempt-footer">
                <button
                  v-if="isStudentExamSubmitted"
                  type="button"
                  class="ghost-btn"
                  @click="showStudentExamResultSummary"
                >
                  Result Summary
                </button>

                <button
                  v-else-if="currentStudentExamQuestion && isStudentOpenNavigationMode"
                  type="button"
                  class="bookmark-toggle-btn"
                  :class="{ 'is-bookmarked': currentStudentExamQuestion.is_bookmarked, 'is-loading': studentExamBookmarking }"
                  :aria-pressed="currentStudentExamQuestion.is_bookmarked ? 'true' : 'false'"
                  :title="currentStudentExamQuestion.is_bookmarked ? 'Remove bookmark' : 'Bookmark this question'"
                  :disabled="studentExamSaving || studentExamSubmitting || studentExamBookmarking"
                  @click="toggleCurrentQuestionBookmark"
                >
                  <RefreshCw v-if="studentExamBookmarking" :size="14" class="spin-soft" />
                  <BookmarkCheck v-else-if="currentStudentExamQuestion.is_bookmarked" :size="14" />
                  <Bookmark v-else :size="14" />
                  <span class="bookmark-toggle-label">
                    {{ currentStudentExamQuestion.is_bookmarked ? 'Bookmarked' : 'Bookmark' }}
                  </span>
                </button>

                <div class="exam-attempt-nav">
                  <button
                    type="button"
                    class="ghost-btn exam-sidebar-inline-toggle"
                    :aria-expanded="(!examAttemptSidebarCollapsed).toString()"
                    @click="toggleExamAttemptSidebar"
                  >
                    {{ examQuestionListToggleLabel }}
                    <ChevronRight :size="15" :class="{ 'is-open': !examAttemptSidebarCollapsed }" />
                  </button>
                  <button
                    type="button"
                    class="ghost-btn"
                    :disabled="studentExamCurrentIndex === 0 || studentExamSaving || studentExamSubmitting || studentExamBookmarking"
                    @click="goToStudentExamQuestion(-1)"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    class="ghost-btn"
                    :disabled="studentExamCurrentIndex >= studentExamQuestions.length - 1 || studentExamSaving || studentExamSubmitting || studentExamBookmarking"
                    @click="goToStudentExamQuestion(1)"
                  >
                    Next
                  </button>
                </div>

                <button
                  v-if="!isStudentExamSubmitted"
                  type="button"
                  class="primary-btn"
                  :disabled="studentExamSubmitting"
                  @click="openStudentExamSubmitConfirm"
                >
                  <RefreshCw v-if="studentExamSubmitting" :size="14" class="spin-soft" />
                  <span>{{ studentExamSubmitting ? 'Submitting...' : 'Submit Exam' }}</span>
                </button>
              </div>
            </section>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div
        v-if="showStudentSubmitConfirmModal && !isStudentExamSubmitted"
        class="modal-backdrop exam-attempt-modal-backdrop"
        @click.self="closeStudentExamSubmitConfirm"
      >
        <div class="modal-card">
          <header class="modal-head">
            <h4>Submit Exam?</h4>
            <button type="button" class="modal-close" :disabled="studentExamSubmitting" @click="closeStudentExamSubmitConfirm">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Submitting your exam is final and cannot be undone.</p>

          <div v-if="studentExamUnansweredCount > 0" class="feedback danger">
            <AlertCircle :size="15" />
            <span>
              You still have
              <strong>{{ studentExamUnansweredCount }}</strong>
              unanswered {{ studentExamUnansweredCount === 1 ? 'item' : 'items' }}.
            </span>
          </div>

          <div v-else class="feedback info">
            <CheckCircle2 :size="15" />
            <span>All items are answered. Ready to submit.</span>
          </div>

          <div v-if="studentExamSaving || studentExamBookmarking" class="feedback info">
            <RefreshCw :size="14" class="spin-soft" />
            <span>Syncing your latest answer changes...</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="studentExamSubmitting" @click="closeStudentExamSubmitConfirm">
              Cancel
            </button>
            <button
              type="button"
              class="danger-btn"
              :disabled="studentExamSubmitting"
              @click="confirmStudentExamSubmit"
            >
              <RefreshCw v-if="studentExamSubmitting" :size="14" class="spin-soft" />
              <span>{{ studentExamSubmitting ? 'Submitting...' : 'Submit Final' }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div
        v-if="showStudentExitConfirmModal && !isStudentExamSubmitted"
        class="modal-backdrop exam-attempt-modal-backdrop"
        @click.self="closeStudentExamExitConfirm"
      >
        <div class="modal-card">
          <header class="modal-head">
            <h4>Exit Exam?</h4>
            <button type="button" class="modal-close" :disabled="studentExamSubmitting" @click="closeStudentExamExitConfirm">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Are you sure you want to exit this exam? You can return as long as the exam remains available.</p>

          <div class="feedback danger">
            <AlertCircle :size="15" />
            <span>Any unsynced changes in your current response may not be saved.</span>
          </div>

          <div v-if="studentExamSaving || studentExamBookmarking" class="feedback info">
            <RefreshCw :size="14" class="spin-soft" />
            <span>Please wait, your latest changes are still syncing.</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="studentExamSubmitting" @click="closeStudentExamExitConfirm">
              Stay
            </button>
            <button type="button" class="danger-btn" :disabled="studentExamSubmitting" @click="confirmStudentExamExit">
              Exit Exam
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import {
  AlertCircle,
  Bookmark,
  BookmarkCheck,
  BookOpen,
  CheckCircle2,
  ChevronRight,
  CircleUserRound,
  Clock3,
  DoorOpen,
  LogOut,
  RefreshCw,
  Search,
  Users,
  X,
} from 'lucide-vue-next'
import { examDeliveryModeLabel, formatLongDate, formatRemainingDuration, formatTimeOnly } from '../composables/useDashboardFormatters'
import { examScheduleEnd, isStudentExamEnded } from '../composables/studentExamAvailability'
import { useRoomsModule } from '../composables/useRoomsModule'
import { useCompactLayout } from '../composables/useViewport'
import BottomSheet from '@/components/BottomSheet.vue'

// The room picker lives in the nav drawer at this width (see
// DashboardLayout), so the page's Join Room action moves to the app bar --
// otherwise hiding the picker would take the only way to join a second room
// with it.
const isCompact = useCompactLayout()

// Local, not composable state -- purely a "show me the archived exams in
// this room too" reveal, reset per page load like every other UI-only toggle
// on this view.
const showArchivedExams = ref(false)

const {
  joinCode,
  roomSearchQuery,
  filteredRooms,
  rooms,
  selectedRoomId,
  selectedRoom,
  showJoinRoomModal,
  showLeaveRoomModal,
  roomLoading,
  roomDetailsLoading,
  roomError,
  roomMessage,
  showExamSimulationModal,
  showStudentSubmitConfirmModal,
  showStudentExitConfirmModal,
  selectedStudentExam,
  studentExamAttempt,
  studentExamQuestions,
  studentExamCurrentIndex,
  isExamMobileViewport,
  examAttemptSidebarCollapsed,
  studentExamLoading,
  studentExamSaving,
  studentExamBookmarking,
  studentExamSubmitting,
  studentExamError,
  studentExamRemainingSeconds,
  studentAnswerDraft,
  selectedRoomActiveExams,
  selectedRoomArchivedExams,
  selectedRoomCurrentMembers,
  selectedRoomYearSummary,
  selectedRoomRosterCopy,
  selectedRoomExamSummary,
  currentStudentExamQuestion,
  currentQuestionStem,
  isStudentExamSubmitted,
  isStudentExamResultSummaryVisible,
  isStudentOpenNavigationMode,
  isCurrentQuestionInputLocked,
  studentExamUnansweredCount,
  yearLevelLabel,
  canStudentOpenExam,
  isStudentExamInProgress,
  isStudentExamCompleted,
  isStudentExamRetakeLimitReached,
  studentExamActionLabel,
  studentExamAvailabilityText,
  archivedExamAction,
  archivedExamActionLabel,
  archivedExamStatusText,
  openArchivedExam,
  examOptionCardClass,
  questionPaletteClass,
  studentQuestionReviewStatus,
  studentQuestionReviewLabel,
  studentQuestionReviewMessage,
  studentQuestionCorrectAnswerText,
  toggleExamAttemptSidebar,
  setExamQuestionListOpen,
  pickStudentExamQuestionFromList,
  openExamSimulation,
  goToStudentExamQuestion,
  handleExamAttemptCloseClick,
  showStudentExamResultSummary,
  closeStudentExamExitConfirm,
  confirmStudentExamExit,
  openStudentExamSubmitConfirm,
  closeStudentExamSubmitConfirm,
  confirmStudentExamSubmit,
  handleStudentOptionSelect,
  handleStudentOpenEndedBlur,
  toggleCurrentQuestionBookmark,
  openJoinRoomModal,
  closeJoinRoomModal,
  openLeaveRoomModal,
  closeLeaveRoomModal,
  selectRoom,
  handleJoinRoom,
  handleLeaveRoom,
} = useRoomsModule({ mode: 'student' })

const examProgressPercent = computed(() => {
  const total = Number(studentExamAttempt.value?.total_items ?? 0)
  if (total === 0) return 0

  return Math.round((Number(studentExamAttempt.value?.answered_count ?? 0) / total) * 100)
})

// What the question list's container is given: the sheet takes its open
// state and a heading, the column only its classes.
const examQuestionListContainerProps = computed(() => {
  if (!isExamMobileViewport.value) {
    return { class: ['exam-attempt-sidebar', { 'is-collapsed': examAttemptSidebarCollapsed.value }] }
  }

  const canJump = isStudentOpenNavigationMode.value || isStudentExamSubmitted.value

  return {
    open: !examAttemptSidebarCollapsed.value,
    title: 'Question List',
    subtitle: canJump
      ? 'Tap a number to go to that question.'
      : 'This exam moves one question at a time.',
  }
})

// On a phone or tablet the list is a sheet that this button opens, and the
// button shares its row with Bookmark, so it carries the name alone. From
// 901px up it shows and hides a column and says which.
const examQuestionListToggleLabel = computed(() => {
  if (isExamMobileViewport.value) return 'Question List'

  return examAttemptSidebarCollapsed.value ? 'Show Question List' : 'Hide Question List'
})

/*
 * Amber under five minutes, red under one. Time pressure should be readable
 * from across the room rather than requiring you to stop and parse digits --
 * the old treatment was an ordinary pill sitting among four identical ones.
 */
const examTimerTone = computed(() => {
  const remaining = studentExamRemainingSeconds.value
  if (remaining === null) return ''
  if (remaining <= 60) return 'is-critical'
  if (remaining <= 300) return 'is-warning'

  return ''
})
</script>

<style scoped src="../dashboard.css"></style>
<style scoped>
/*
  This page's layout is built entirely with its own classes below rather
  than the shared .room-list-panel/.detail-card/.exam-card family in
  dashboard.css -- shared/RoomManagementView.vue (staff) reuses those exact
  classes for its own room view, so restyling them here would have changed
  that page too. Keeping this redesign local avoids that entirely.
*/

/* Fills the flex-column .layout-content box exactly (see DashboardLayout.vue's
   is-fill-screen chain) so the 3-column grid below takes that fixed height
   instead of growing the page -- the exam list and roster list are what
   scroll internally when they run long (see further down), not the page
   itself. */
.room-view {
  display: flex;
  flex-direction: column;
  gap: 12px;
  flex: 1;
  min-height: 0;
}

.rooms-empty-card {
  display: grid;
  align-content: center;
  justify-items: center;
  gap: 8px;
  text-align: center;
  padding: 48px 20px;
  color: var(--lnu-text-muted);
  flex: 1;
  min-height: 0;
}

.rooms-empty-card h4 {
  margin: 0;
  color: var(--lnu-text);
}

.rooms-empty-card p {
  margin: 0;
  max-width: 320px;
}

/* Three independent cards side by side, not one shell around two panes --
   room list | banner + exams | class list + quick summary. Stretch (not
   start) so all three columns share the same height, and flex:1/min-height:0
   let this fill the .room-view box (there's no separate page head above it
   any more -- the shared layout topbar already says "Rooms"). */
.rooms-grid {
  display: grid;
  grid-template-columns: 240px minmax(0, 1.4fr) 260px;
  align-items: stretch;
  gap: 16px;
  flex: 1;
  min-height: 0;
}

.room-detail-column,
.room-side-column {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
  min-height: 0;
}

/* ---- Room list card ---- */
.rooms-list-card {
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 0;
  background: #fff;
}

.rooms-list-head {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.rooms-list-label {
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--lnu-text-muted);
}

.rooms-count-badge {
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.1);
  color: var(--lnu-navy);
  font-size: 11px;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

/* Lives here now (used to sit in a separate page head above the whole
   grid, duplicating the shared layout topbar's own "Rooms" title) --
   full-width fits this narrow column better than a squeezed inline button
   next to the "My Rooms" label. */
.rooms-join-btn {
  flex-shrink: 0;
  width: 100%;
  height: 36px;
  justify-content: center;
  gap: 6px;
}

.rooms-search {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  height: 34px;
  padding: 0 10px;
  border-radius: 8px;
  border: 1px solid rgba(26, 35, 126, 0.16);
  background: #f7f8fb;
  color: var(--lnu-text-muted);
}

.rooms-search input {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  font-size: 13px;
  color: var(--lnu-text);
  outline: none;
}

.rooms-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(26, 35, 126, 0.22) transparent;
}

.rooms-list-item {
  flex-shrink: 0;
  text-align: left;
  border: 1px solid rgba(26, 35, 126, 0.12);
  border-radius: 10px;
  padding: 10px 12px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 3px;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.rooms-list-item:hover:not(.active) {
  background: rgba(26, 35, 126, 0.04);
}

.rooms-list-item.active {
  background: var(--lnu-navy);
  border-color: var(--lnu-navy);
  color: #fff;
}

.rooms-list-item-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}

.rooms-list-item strong {
  font-size: 14px;
  font-weight: 700;
}

.rooms-list-chevron {
  flex-shrink: 0;
  opacity: 0.6;
}

.rooms-list-code {
  margin: 0;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.03em;
  opacity: 0.75;
}

.rooms-list-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 2px;
}

.rooms-list-meta span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  opacity: 0.85;
}

.rooms-list-by {
  margin: 0;
  font-size: 11px;
  opacity: 0.7;
}

.rooms-list-empty {
  padding: 10px 2px;
  font-size: 13px;
}

/* ---- Room banner ---- */
/* flex-shrink:0 -- .rooms-exams-card below is flex:1 (flex-basis 0%), which
   would otherwise take all shrink weight in a tight column first. */
.room-banner {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  flex-wrap: wrap;
  border-radius: var(--radius-lg);
  padding: 18px 22px;
  background: var(--panel-navy);
  color: #fff;
  box-shadow: 0 10px 24px rgba(13, 21, 71, 0.2);
}

.room-banner-copy h4 {
  margin: 0;
  font-size: 22px;
  font-weight: 800;
}

.room-banner-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.room-banner-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 11px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.2);
  font-size: 12px;
  font-weight: 600;
}

.room-banner-leave-btn {
  flex-shrink: 0;
}

/* ---- Current exams ---- */
.rooms-exams-card {
  padding: 18px;
  background: #fff;
  display: flex;
  flex-direction: column;
  min-height: 0;
  flex: 1;
}

.rooms-exams-head {
  flex-shrink: 0;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
}

.rooms-exams-head h5 {
  margin: 0;
  font-size: 16px;
  font-weight: 800;
  color: var(--lnu-navy);
}

.rooms-exams-head p {
  margin: 3px 0 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.rooms-link-btn {
  flex-shrink: 0;
  border: none;
  background: transparent;
  color: var(--lnu-navy);
  font-size: 12px;
  font-weight: 700;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.rooms-link-btn:hover {
  color: var(--lnu-navy-light);
}

/* One exam per row (not a card grid) -- title + action on top, items/
   duration/window info on the bottom, same way a list of assignments
   reads rather than a gallery of tiles. */
/* flex column, not grid -- a flex item never inflates along the main axis
   without an explicit flex-grow, which is what a plain grid's auto rows
   would otherwise do here (see .rooms-exam-row below for where that bug
   actually showed up). Guarantees rows pack tightly regardless of how
   much extra height this list ends up with. */
.rooms-exam-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(26, 35, 126, 0.22) transparent;
}

.rooms-exam-list.archived {
  flex: none;
  max-height: 260px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px dashed rgba(26, 35, 126, 0.14);
}

.rooms-exam-row {
  flex-shrink: 0;
  border: 1px solid rgba(26, 35, 126, 0.12);
  border-radius: 14px;
  padding: 12px 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: #fafbfd;
}

.rooms-exam-row.archived {
  background: #f4f5f8;
}

/* Text stays fully legible even when the exam can't be opened -- only the
   action button itself (see .rooms-exam-btn:disabled) signals "locked."
   Dimming the whole row too just made the title/meta hard to read on top
   of an already-disabled button. */
.rooms-exam-row.locked {
  background: #f6f7fa;
}

.rooms-exam-row-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
}

.rooms-exam-title {
  font-size: 14px;
  font-weight: 700;
  color: var(--lnu-navy);
  line-height: 1.3;
}

.rooms-exam-row-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
}

.rooms-exam-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
}

.rooms-exam-meta span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.rooms-exam-window {
  text-align: right;
  display: grid;
  gap: 2px;
}

.rooms-exam-window-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lnu-text-muted);
}

.rooms-exam-window-date {
  font-size: 12px;
  font-weight: 700;
  color: var(--lnu-text);
}

.rooms-exam-window-fallback {
  margin: 0;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: var(--lnu-text-muted);
}

.rooms-exam-btn {
  flex-shrink: 0;
  height: 34px;
  padding: 0 16px;
  border-radius: 8px;
  border: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  white-space: nowrap;
  background: linear-gradient(135deg, var(--lnu-navy), var(--lnu-navy-light));
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.rooms-exam-btn:hover:not(:disabled) {
  background: linear-gradient(135deg, var(--lnu-navy-light), var(--lnu-navy));
}

.rooms-exam-btn.is-resume {
  background: linear-gradient(135deg, var(--lnu-navy), var(--lnu-navy-light));
}

/* Teal outline, not filled -- "you've already passed through this one,
   here's a lower-emphasis way back in" reads calmer than a solid button. */
.rooms-exam-btn.is-retake {
  background: #fff;
  border: 1.5px solid #0f9d78;
  color: #0f9d78;
}

.rooms-exam-btn.is-retake:hover:not(:disabled) {
  background: rgba(15, 157, 120, 0.08);
}

.rooms-exam-btn.is-review {
  background: #fff;
  border: 1.5px solid rgba(26, 35, 126, 0.28);
  color: var(--lnu-text-muted);
}

.rooms-exam-btn.is-review:hover:not(:disabled) {
  background: rgba(26, 35, 126, 0.05);
}

/* Declared last (equal specificity to the .is-* rules above, so source
   order decides) and skips opacity entirely -- a flat opacity dim was
   crushing an already-light teal/gray outline into near-illegible text.
   A disabled exam (window closed) reads as a clean neutral "unavailable"
   chip instead, regardless of which tone it would otherwise have been. */
.rooms-exam-btn:disabled {
  background: #f4f5f8;
  border-color: rgba(26, 35, 126, 0.14);
  color: var(--lnu-text-muted);
  cursor: not-allowed;
}

.rooms-detail-loading,
.rooms-detail-empty {
  display: grid;
  /* align-content:center packs the icon/heading/text together as one
     centered group -- without it, a flex:1-stretched grid here defaults to
     spreading each row across the full height instead (the same bug fixed
     on the dashboard home page's empty-notices state). */
  align-content: center;
  justify-items: center;
  gap: 8px;
  text-align: center;
  padding: 48px 20px;
  color: var(--lnu-text-muted);
  background: #fff;
  flex: 1;
  min-height: 0;
}

.rooms-detail-empty h4 {
  margin: 0;
  color: var(--lnu-text);
}

.rooms-detail-empty p {
  margin: 0;
  max-width: 260px;
}

/* ---- Class list + quick summary ---- */
.rooms-roster-card {
  padding: 16px;
  background: #fff;
  display: flex;
  flex-direction: column;
  min-height: 0;
  flex: 1;
}

.rooms-roster-head {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--lnu-navy);
}

.rooms-roster-head h5 {
  margin: 0;
  font-size: 15px;
  font-weight: 800;
}

.rooms-roster-tags {
  flex-shrink: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}

.rooms-tag {
  display: inline-flex;
  align-items: center;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.rooms-tag.success { background: rgba(46, 125, 50, 0.14); color: var(--lnu-success); }
.rooms-tag.neutral { background: rgba(26, 35, 126, 0.08); color: var(--lnu-text-muted); }

.rooms-tag.small {
  margin-left: auto;
  flex-shrink: 0;
}

.rooms-roster-copy {
  flex-shrink: 0;
  margin: 8px 0 0;
  font-size: 12px;
}

.rooms-roster-list {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(26, 35, 126, 0.22) transparent;
}

.rooms-roster-item {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 10px;
  background: #f7f8fb;
}

.rooms-roster-avatar {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: rgba(26, 35, 126, 0.08);
  color: var(--lnu-text-muted);
}

.rooms-roster-item-copy {
  min-width: 0;
  flex: 1;
}

.rooms-roster-item-copy strong {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: var(--lnu-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rooms-roster-item-copy p {
  margin: 1px 0 0;
  font-size: 11px;
  color: var(--lnu-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* flex-shrink:0 -- .rooms-roster-card above uses flex:1 (flex-basis 0%),
   which without this would take 100% of any shrink weight in a tight
   column and squeeze this card's ring... same bug already found and fixed
   on the dashboard home page's outcome card (see DashboardHomeView.vue). */
.rooms-summary-card {
  flex-shrink: 0;
  padding: 16px;
  border-radius: var(--radius-md);
  background: rgba(var(--lnu-info-rgb), 0.08);
  border: 1px solid rgba(var(--lnu-info-rgb), 0.16);
}

.rooms-summary-title {
  margin: 0 0 10px;
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--lnu-navy);
}

.rooms-summary-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  color: var(--lnu-text);
}

.rooms-summary-row strong {
  color: var(--lnu-navy);
  font-size: 15px;
}

.rooms-summary-bar {
  margin-top: 8px;
  height: 6px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.12);
  overflow: hidden;
}

.rooms-summary-bar-fill {
  height: 100%;
  border-radius: 999px;
  background: var(--lnu-navy);
  transition: width 0.3s ease;
}

.rooms-summary-stats {
  margin-top: 14px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.rooms-summary-stat {
  display: grid;
  gap: 2px;
  text-align: center;
  padding: 10px 6px;
  border-radius: 10px;
  background: #fff;
  border: 1px solid rgba(var(--lnu-info-rgb), 0.14);
}

.rooms-summary-stat strong {
  font-size: 18px;
  font-weight: 800;
  color: var(--lnu-navy);
}

.rooms-summary-stat span {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lnu-text-muted);
}

@media (max-width: 1200px) {
  .rooms-grid {
    grid-template-columns: 220px minmax(0, 1fr);
  }

  .room-side-column {
    grid-column: 1 / -1;
  }

  .room-side-column {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

/*
 * Between 901px and 1200px the panels sit in two rows, and two rows cannot
 * share one screen: on a 1024x768 lab monitor the exam list was an 80px
 * window. Here the page stops pinning itself to the screen height, each
 * list gets a workable window, and the page scrolls down to the class list.
 * (Same rule as the staff page, shared/RoomManagementView.vue.)
 *
 * The same on any wider screen that is short. A 1366x768 laptop shows a
 * page about 640px of height once the browser's own bars are counted, and
 * pinned to that the exam list was a 284px window.
 */
@media (min-width: 901px) and (max-width: 1200px), (min-width: 901px) and (max-height: 759px) {
  .room-view,
  .rooms-grid {
    flex: none;
  }

  .rooms-grid {
    align-items: start;
  }

  .rooms-list-card {
    max-height: 560px;
  }

  .rooms-exam-list {
    max-height: 420px;
  }

  .rooms-roster-list {
    max-height: 460px;
  }
}

/* Only where the side panels are a two-column grid. From 1201px they are a
   column, and there this would let each card take the width of its longest
   name instead of the column's. */
@media (min-width: 901px) and (max-width: 1200px) {
  .room-side-column {
    align-items: start;
  }
}

@media (max-width: 900px) {
  /* minmax(0, ...), not a bare 1fr: a bare one cannot shrink below its
     widest unbreakable line, and one long name in the class list pushed
     this page 19px past the edge of a 390px phone. */
  .rooms-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  /*
   * The room picker moves into the nav drawer on phones (see the Rooms
   * sub-navigation in DashboardLayout), so the page no longer spends its
   * first screenful on a list before showing the room you are actually in.
   * The join action moves with it -- the empty state above still offers
   * "Join Room" for someone with no rooms yet.
   */
  .rooms-list-card {
    display: none;
  }

  .room-side-column {
    grid-template-columns: minmax(0, 1fr);
  }

  .room-banner {
    flex-direction: column;
    align-items: flex-start;
  }

  /* A quiet outline, at its own width. Stretched across the banner in red
     it was the largest thing on the page, on the page a student opens to
     take an exam. */
  .room-banner-leave-btn {
    border: 1px solid rgba(255, 255, 255, 0.42);
    background: transparent;
    box-shadow: none;
    color: #fff;
  }

  .room-banner-leave-btn:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
  }

  /* The summary is three numbers; the class list under it can be fifty
     rows. The other way round nobody reached the summary. */
  .rooms-summary-card {
    order: -1;
  }
}

@media (max-width: 640px) {
  /*
   * An exam reads top to bottom: its name, what it holds, when its window
   * closes, then the button at full width. Side by side, the button took
   * 126px of a 320px screen and pushed the title out past the card's edge.
   * The head dissolves so the button can come after the row's last line.
   */
  .rooms-exam-row-head {
    display: contents;
  }

  .rooms-exam-row-foot {
    order: 1;
  }

  .rooms-exam-btn {
    order: 2;
    width: 100%;
  }

  .rooms-exam-window {
    text-align: left;
  }

  /* A classmate's whole name, on two lines if it needs them. */
  .rooms-roster-item-copy strong {
    white-space: normal;
    overflow-wrap: break-word;
  }
}

/* On the narrowest phones a year tag beside the name left the name about
   60px, one syllable a line. The tag goes under it, in line with the text
   (42px is the avatar and its gap). */
@media (max-width: 380px) {
  .rooms-roster-item {
    flex-wrap: wrap;
  }

  .rooms-roster-item-copy {
    flex-basis: calc(100% - 42px);
  }

  .rooms-tag.small {
    margin-left: 42px;
  }
}

@media (pointer: coarse) {
  .rooms-exam-btn,
  .rooms-join-btn {
    height: 44px;
  }

  .rooms-search {
    height: 44px;
  }

  .rooms-search input {
    align-self: stretch;
    font-size: 16px;
  }

  /* A text link 16px tall; the padding is its tap area. */
  .rooms-link-btn {
    min-height: 44px;
    margin-block: -12px;
    padding-inline: 4px;
  }
}

</style>
