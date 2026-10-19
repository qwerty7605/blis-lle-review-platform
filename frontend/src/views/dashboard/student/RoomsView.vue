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
      <h4>Join a Room <InfoTip text="Use the room code from your teacher to join and see the exams assigned to you and your classmates." /></h4>
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
                    <span v-if="studentAttemptSummary(exam)" class="rooms-exam-attempts">{{ studentAttemptSummary(exam) }}</span>
                  </div>

                  <div v-if="isStudentExamEnded(exam) && studentAttemptFinishBy(exam)" class="rooms-exam-window">
                    <span class="rooms-exam-window-label">Finish By</span>
                    <span class="rooms-exam-window-date">
                      {{ formatLongDate(studentAttemptFinishBy(exam)) }} at {{ formatTimeOnly(studentAttemptFinishBy(exam)) }}
                    </span>
                  </div>
                  <div v-else-if="isStudentExamEnded(exam)" class="rooms-exam-window">
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
                    <span v-if="studentAttemptSummary(exam)" class="rooms-exam-attempts">{{ studentAttemptSummary(exam) }}</span>
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

          <!-- The page's own banner is behind this dialog. -->
          <div v-if="roomError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ roomError }}</span>
          </div>

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
      <!-- Questions cannot be selected, copied or dragged out, so they are not
           pasted into a search; a written answer's own box still works. -->
      <div
        v-if="showExamSimulationModal"
        class="exam-attempt-backdrop exam-no-copy"
        @copy="blockExamCopy"
        @cut="blockExamCopy"
        @contextmenu="blockExamCopy"
        @dragstart="blockExamCopy"
        @selectstart="blockExamCopy"
      >
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
            :class="{ 'is-results-view': isStudentExamResultSummaryVisible }"
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
                  <span class="legend-item"><i class="legend-swatch answered" /> Answered</span>
                  <span class="legend-item"><i class="legend-swatch unanswered" /> Unanswered</span>
                  <span class="legend-item"><i class="legend-swatch current" /> Current</span>
                  <span class="legend-item"><i class="legend-ribbon" /> Marked</span>
                </template>
                <template v-else>
                  <span class="legend-item"><i class="legend-dot current-outline" /> Current</span>
                  <span class="legend-item"><i class="legend-dot answered" /> Answered</span>
                  <span class="legend-item"><i class="legend-dot missed" /> Not answered</span>
                  <span class="legend-item"><i class="legend-ribbon" /> Marked</span>
                </template>
              </div>

              <!-- Show only what is left to do. -->
              <div v-if="!isStudentExamSubmitted" class="exam-list-filters" role="group" aria-label="Show questions">
                <button
                  v-for="filter in examListFilters"
                  :key="filter.value"
                  type="button"
                  class="exam-list-filter"
                  :class="{ active: examListFilter === filter.value }"
                  :aria-pressed="examListFilter === filter.value ? 'true' : 'false'"
                  @click="examListFilter = filter.value"
                >
                  <span>{{ filter.label }}</span>
                  <strong>{{ filter.count }}</strong>
                </button>
              </div>

              <div
                v-if="isStudentOpenNavigationMode || isStudentExamSubmitted"
                class="exam-question-jump immersive"
                :class="{ 'is-dense': studentExamQuestions.length > 40 }"
              >
                <template v-for="(question, index) in studentExamQuestions" :key="question.question_id">
                  <button
                    v-if="examListShows(question)"
                    type="button"
                    class="exam-jump-btn"
                    :class="questionPaletteClass(question, index)"
                    :aria-label="examJumpLabel(question, index)"
                    :aria-current="studentExamCurrentIndex === index && !examReviewOpen ? 'step' : undefined"
                    :disabled="studentExamSaving || studentExamSubmitting || studentExamBookmarking || (!isStudentOpenNavigationMode && !isStudentExamSubmitted)"
                    @click="openExamQuestion(index)"
                  >
                    {{ question.item_number }}
                  </button>
                </template>
                <p v-if="examListFilter !== 'all' && examListFilterCount === 0" class="exam-list-empty">
                  {{ examListFilter === 'unanswered' ? 'Every question is answered.' : 'No questions marked for review.' }}
                </p>
              </div>

              <!-- From 901px up Submit sits under the list, as in Moodle's quiz
                   navigation, away from Next so it is never pressed for it. -->
              <div v-if="!isExamMobileViewport && !isStudentExamSubmitted" class="exam-sidebar-submit">
                <p v-if="studentExamUnansweredCount > 0">
                  {{ studentExamUnansweredCount }} {{ studentExamUnansweredCount === 1 ? 'question' : 'questions' }} unanswered
                </p>
                <p v-else>All questions answered</p>
                <button
                  type="button"
                  class="primary-btn"
                  :disabled="studentExamSubmitting"
                  @click="openExamReview"
                >
                  <RefreshCw v-if="studentExamSubmitting" :size="14" class="spin-soft" />
                  <span>{{ studentExamSubmitting ? 'Submitting...' : 'Review & Submit' }}</span>
                </button>
              </div>
            </component>

            <section class="exam-attempt-main">
              <div v-if="isStudentExamResultSummaryVisible" class="room-detail-loading exam-attempt-loading">
                <RefreshCw :size="16" class="spin-soft" />
                <span>Taking you to your results...</span>
              </div>

              <!-- The last step before submitting, as in most exam software:
                   what is unanswered and what is marked, each a click away. -->
              <section
                v-else-if="examReviewOpen && !isStudentExamSubmitted"
                class="exam-review-screen"
                aria-labelledby="exam-review-title"
              >
                <p class="exam-review-kicker">Before you submit</p>
                <h3 id="exam-review-title">Review Your Answers</h3>

                <dl class="exam-review-stats">
                  <div>
                    <dt>Answered</dt>
                    <dd>{{ studentExamQuestions.length - studentExamUnansweredCount }}<small> / {{ studentExamQuestions.length }}</small></dd>
                  </div>
                  <div :class="{ 'is-attention': studentExamUnansweredCount > 0 }">
                    <dt>Unanswered</dt>
                    <dd>{{ studentExamUnansweredCount }}</dd>
                  </div>
                  <div>
                    <dt>Marked for review</dt>
                    <dd>{{ examMarkedIndexes.length }}</dd>
                  </div>
                </dl>

                <div v-if="examUnansweredIndexes.length > 0" class="exam-review-group">
                  <h4>Unanswered</h4>
                  <div class="exam-review-numbers">
                    <button
                      v-for="index in examUnansweredIndexes"
                      :key="`u-${index}`"
                      type="button"
                      class="exam-jump-btn pre-blank"
                      :class="{ 'has-bookmark': studentExamQuestions[index].is_bookmarked }"
                      @click="openExamQuestion(index)"
                    >
                      {{ studentExamQuestions[index].item_number }}
                    </button>
                  </div>
                </div>

                <div v-if="examMarkedIndexes.length > 0" class="exam-review-group">
                  <h4>Marked for review</h4>
                  <div class="exam-review-numbers">
                    <button
                      v-for="index in examMarkedIndexes"
                      :key="`m-${index}`"
                      type="button"
                      class="exam-jump-btn has-bookmark"
                      :class="questionPaletteClass(studentExamQuestions[index], -1)"
                      @click="openExamQuestion(index)"
                    >
                      {{ studentExamQuestions[index].item_number }}
                    </button>
                  </div>
                </div>

                <p v-if="examUnansweredIndexes.length === 0 && examMarkedIndexes.length === 0" class="exam-review-clear">
                  <CheckCircle2 :size="18" /> Every question is answered and none is marked for review.
                </p>

                <p class="exam-review-note">The timer keeps running while you review. Submitting is final.</p>

                <div class="exam-review-actions">
                  <button type="button" class="ghost-btn" @click="closeExamReview">
                    <ArrowLeft :size="16" /> Question {{ currentStudentExamQuestion?.item_number }}
                  </button>
                  <button
                    v-if="examUnansweredIndexes.length > 0"
                    type="button"
                    class="ghost-btn"
                    @click="openExamQuestion(examUnansweredIndexes[0])"
                  >
                    First Unanswered
                  </button>
                  <button type="button" class="primary-btn" :disabled="studentExamSubmitting" @click="openStudentExamSubmitConfirm">
                    <RefreshCw v-if="studentExamSubmitting" :size="14" class="spin-soft" />
                    <span>{{ studentExamSubmitting ? 'Submitting...' : 'Submit Exam' }}</span>
                  </button>
                </div>
              </section>

              <article v-else-if="currentStudentExamQuestion" class="exam-attempt-card immersive">
                <header class="surface-head">
                  <h4>
                    Question {{ currentStudentExamQuestion.item_number }}
                    <span class="exam-question-of">of {{ studentExamQuestions.length }}</span>
                  </h4>
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
                    <CheckCircle2
                      v-if="!isStudentExamSubmitted && studentAnswerDraft.selected_option_id === option.id"
                      class="exam-option-check"
                      aria-hidden="true"
                    />
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

              <div v-if="!isStudentExamResultSummaryVisible && !examReviewOpen" class="exam-attempt-footer">
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
                  :title="currentStudentExamQuestion.is_bookmarked ? 'Remove the mark' : 'Mark this question to come back to it'"
                  :disabled="studentExamSaving || studentExamSubmitting || studentExamBookmarking"
                  @click="toggleCurrentQuestionBookmark"
                >
                  <RefreshCw v-if="studentExamBookmarking" :size="14" class="spin-soft" />
                  <BookmarkCheck v-else-if="currentStudentExamQuestion.is_bookmarked" :size="14" />
                  <Bookmark v-else :size="14" />
                  <span class="bookmark-toggle-label">
                    {{ currentStudentExamQuestion.is_bookmarked ? 'Marked for Review' : 'Mark for Review' }}
                  </span>
                </button>

                <div class="exam-attempt-nav">
                  <!-- Only where the list is a sheet. From 901px up it is a
                       column that is always there, with nothing to open. -->
                  <button
                    v-if="isExamMobileViewport"
                    type="button"
                    class="ghost-btn exam-sidebar-inline-toggle"
                    :aria-expanded="(!examAttemptSidebarCollapsed).toString()"
                    @click="toggleExamAttemptSidebar"
                  >
                    Question List
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
                  <!-- On the last question Next becomes the way to the review
                       screen; after submitting it simply stops there. -->
                  <button
                    type="button"
                    class="ghost-btn"
                    :class="{ 'is-review-step': isLastExamQuestion && !isStudentExamSubmitted }"
                    :disabled="(isLastExamQuestion && isStudentExamSubmitted) || studentExamSaving || studentExamSubmitting || studentExamBookmarking"
                    @click="isLastExamQuestion ? openExamReview() : goToStudentExamQuestion(1)"
                  >
                    {{ isLastExamQuestion && !isStudentExamSubmitted ? 'Review Answers' : 'Next' }}
                  </button>
                </div>

                <button
                  v-if="!isStudentExamSubmitted && isExamMobileViewport"
                  type="button"
                  class="primary-btn"
                  :disabled="studentExamSubmitting"
                  @click="openExamReview"
                >
                  <RefreshCw v-if="studentExamSubmitting" :size="14" class="spin-soft" />
                  <span>{{ studentExamSubmitting ? 'Submitting...' : 'Finish' }}</span>
                </button>
              </div>

              <!-- Shown only by pressing "?": no link on screen, as most exam sites have none. -->
              <div v-if="examShortcutsOpen && !isExamMobileViewport && !isStudentExamResultSummaryVisible && !examReviewOpen" class="exam-shortcuts">
                <dl id="exam-shortcuts-list" class="exam-shortcuts-list">
                  <div>
                    <dt><kbd aria-label="Left arrow"><ArrowLeft :size="12" /></kbd><kbd aria-label="Right arrow"><ArrowRight :size="12" /></kbd></dt>
                    <dd>Previous / next question</dd>
                  </div>
                  <div v-if="!isStudentExamSubmitted">
                    <dt><kbd aria-label="Up arrow"><ArrowUp :size="12" /></kbd><kbd aria-label="Down arrow"><ArrowDown :size="12" /></kbd></dt>
                    <dd>Move the answer up or down</dd>
                  </div>
                  <div v-if="!isStudentExamSubmitted">
                    <dt><kbd>A</kbd>–<kbd>{{ lastOptionLetter }}</kbd></dt>
                    <dd>Choose that answer</dd>
                  </div>
                  <div>
                    <dt><kbd>Enter</kbd></dt>
                    <dd>{{ isStudentExamSubmitted ? 'Next question' : 'Next question; on the last, review your answers' }}</dd>
                  </div>
                  <div>
                    <dt><kbd>?</kbd></dt>
                    <dd>Show or hide this list</dd>
                  </div>
                </dl>
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
import InfoTip from '@/components/InfoTip.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  AlertCircle,
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  ArrowUp,
  BookOpen,
  Bookmark,
  BookmarkCheck,
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
import { examScheduleEnd, isStudentExamEnded, studentAttemptFinishBy } from '../composables/studentExamAvailability'
import { useRoomsModule } from '../composables/useRoomsModule'
import { useCompactLayout } from '../composables/useViewport'
import BottomSheet from '@/components/BottomSheet.vue'

// Inside the exam: copying or selecting the questions is refused, except in a
// field the student types into (a written answer).
function blockExamCopy(event) {
  if (event.target?.closest?.('input, textarea, [contenteditable="true"]')) return

  event.preventDefault()
}

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
  questionHasAnswer,
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

// "Attempt 2 of 2 · Latest 80.0%": how many times the exam has been sat,
// and the score that counts, which is the latest sitting's.
function studentAttemptSummary(exam) {
  const taken = Number(exam.student_submitted_attempts ?? 0)
  if (taken < 1) return ''

  const allowed = Math.max(taken, Number(exam.student_max_attempts ?? taken))
  const score = exam.student_latest_score_percent
  const scoreText = score === null || score === undefined ? '' : ` · ${taken > 1 ? 'Latest ' : ''}${Number(score).toFixed(1)}%`

  return `Attempt ${taken} of ${allowed}${scoreText}`
}

const examProgressPercent = computed(() => {
  const total = Number(studentExamAttempt.value?.total_items ?? 0)
  if (total === 0) return 0

  return Math.round((Number(studentExamAttempt.value?.answered_count ?? 0) / total) * 100)
})

// What the question list's container is given: the sheet takes its open
// state and a heading, the column only its classes.
const examQuestionListContainerProps = computed(() => {
  if (!isExamMobileViewport.value) {
    return { class: 'exam-attempt-sidebar' }
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

// ------------------------------------------------------------- keyboard
// As in most computer-based exams: left and right (and Enter) move between
// questions, up and down or a choice's letter pick the answer. Enter only
// ever moves on; it never submits. Nothing here acts while the student is
// typing a written answer, or while a dialog or the phone's list is open.
const lastOptionLetter = computed(() => {
  const options = currentStudentExamQuestion.value?.options ?? []

  return String(options[options.length - 1]?.label ?? 'D').toUpperCase()
})

const examBusy = computed(() => studentExamSaving.value || studentExamSubmitting.value || studentExamBookmarking.value)

// ---------------------------------------------- question list and review
const examReviewOpen = ref(false)
const examShortcutsOpen = ref(false)
const examListFilter = ref('all')

const isLastExamQuestion = computed(() => (
  studentExamQuestions.value.length > 0 && studentExamCurrentIndex.value >= studentExamQuestions.value.length - 1
))

const examUnansweredIndexes = computed(() => studentExamQuestions.value
  .map((question, index) => (questionHasAnswer(question) ? -1 : index))
  .filter((index) => index >= 0))

const examMarkedIndexes = computed(() => studentExamQuestions.value
  .map((question, index) => (question.is_bookmarked ? index : -1))
  .filter((index) => index >= 0))

const examListFilters = computed(() => [
  { value: 'all', label: 'All', count: studentExamQuestions.value.length },
  { value: 'unanswered', label: 'Unanswered', count: examUnansweredIndexes.value.length },
  { value: 'marked', label: 'Marked', count: examMarkedIndexes.value.length },
])

const examListFilterCount = computed(() => (
  examListFilters.value.find((filter) => filter.value === examListFilter.value)?.count ?? 0
))

function examListShows(question) {
  if (isStudentExamSubmitted.value || examListFilter.value === 'all') return true
  if (examListFilter.value === 'marked') return Boolean(question.is_bookmarked)

  return !questionHasAnswer(question)
}

function examJumpLabel(question, index) {
  const parts = [`Question ${question.item_number}`]
  if (!isStudentExamSubmitted.value) parts.push(questionHasAnswer(question) ? 'answered' : 'unanswered')
  if (question.is_bookmarked) parts.push('marked for review')
  if (index === studentExamCurrentIndex.value) parts.push('current')

  return parts.join(', ')
}

function openExamQuestion(index) {
  examReviewOpen.value = false
  pickStudentExamQuestionFromList(index)
}

function openExamReview() {
  if (isStudentExamSubmitted.value || examBusy.value) return

  examShortcutsOpen.value = false
  examReviewOpen.value = true
  // On a phone the question list is a sheet; the review replaces it.
  if (isExamMobileViewport.value) setExamQuestionListOpen(false)
}

function closeExamReview() {
  examReviewOpen.value = false
}

// A new exam, or one just submitted, starts on its questions, unfiltered.
watch(() => studentExamAttempt.value?.id, () => {
  examReviewOpen.value = false
  examListFilter.value = 'all'
})

watch(isStudentExamSubmitted, (submitted) => {
  if (submitted) examReviewOpen.value = false
})

// A key pressed while the answer just chosen is still saving is kept, and
// acted on once it has saved, so a quick "B then Enter" is not lost.
let pendingExamStep = 0

function stepExamQuestion(step) {
  const target = studentExamCurrentIndex.value + step
  // Past the last question is the review screen (never a submit).
  if (step > 0 && target > studentExamQuestions.value.length - 1 && !isStudentExamSubmitted.value) {
    if (examBusy.value) {
      pendingExamStep = step
      return
    }

    openExamReview()
    return
  }
  if (target < 0 || target > studentExamQuestions.value.length - 1) return

  if (examBusy.value) {
    pendingExamStep = step
    return
  }

  goToStudentExamQuestion(step)
}

watch(examBusy, (busy) => {
  if (busy || !pendingExamStep) return

  const step = pendingExamStep
  pendingExamStep = 0
  stepExamQuestion(step)
})

function chooseExamOption(option) {
  if (!option || isCurrentQuestionInputLocked.value) return
  if (studentAnswerDraft.selected_option_id === option.id) return

  handleStudentOptionSelect(option.id)
}

function handleExamKeydown(event) {
  if (!showExamSimulationModal.value || studentExamLoading.value || !studentExamAttempt.value) return
  if (isStudentExamResultSummaryVisible.value || !currentStudentExamQuestion.value) return
  if (showStudentSubmitConfirmModal.value || showStudentExitConfirmModal.value) return
  if (isExamMobileViewport.value && !examAttemptSidebarCollapsed.value) return
  if (document.querySelector('body > .modal-backdrop')) return
  if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return

  const target = event.target
  if (target?.closest?.('textarea, select, [contenteditable="true"], input:not([type="radio"])')) return
  // A focused button keeps its own Enter and Space.
  if ((event.key === 'Enter' || event.key === ' ') && target?.closest?.('button, a')) return

  if (event.key === '?') {
    event.preventDefault()
    if (!examReviewOpen.value && !isExamMobileViewport.value) examShortcutsOpen.value = !examShortcutsOpen.value
    return
  }

  if (event.key === 'Escape' && examShortcutsOpen.value) {
    examShortcutsOpen.value = false
    return
  }

  // On the review screen only "back" means anything; Enter never submits.
  if (examReviewOpen.value) {
    if (event.key === 'ArrowLeft' || event.key === 'Escape') {
      event.preventDefault()
      closeExamReview()
    }
    return
  }

  const question = currentStudentExamQuestion.value
  const options = question.question_type === 'open_ended' ? [] : (question.options ?? [])

  switch (event.key) {
    case 'ArrowLeft':
      event.preventDefault()
      stepExamQuestion(-1)
      return
    case 'ArrowRight':
    case 'Enter':
      event.preventDefault()
      stepExamQuestion(1)
      return
    case 'ArrowUp':
    case 'ArrowDown': {
      if (options.length === 0) return
      event.preventDefault()
      const current = options.findIndex((option) => option.id === studentAnswerDraft.selected_option_id)
      const next = current < 0
        ? (event.key === 'ArrowDown' ? 0 : options.length - 1)
        : Math.min(Math.max(current + (event.key === 'ArrowDown' ? 1 : -1), 0), options.length - 1)
      chooseExamOption(options[next])
      return
    }
    default: {
      if (event.key.length !== 1 || options.length === 0) return
      const option = options.find((candidate) => String(candidate.label ?? '').toLowerCase() === event.key.toLowerCase())
      if (!option) return
      event.preventDefault()
      chooseExamOption(option)
    }
  }
}

onMounted(() => window.addEventListener('keydown', handleExamKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', handleExamKeydown))

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

<style scoped src="./roomsView.css"></style>
