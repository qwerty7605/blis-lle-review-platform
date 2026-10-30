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

    <article v-if="roomLiveBoardActive" class="surface-card room-shell-card room-live-board-shell">
      <header class="room-page-head">
        <div class="room-page-title">
          <BarChart3 :size="18" />
          <h3>{{ liveBoardExam?.title || 'Live Quiz Board' }}</h3>
        </div>
        <button type="button" class="ghost-btn" @click="closeRoomLiveBoard">
          <ChevronLeft :size="14" />
          Back to Room
        </button>
      </header>

      <p class="muted room-live-board-sub">
        <span>{{ liveBoardRoom?.name || selectedRoom?.name || 'Room' }}</span>
        <span v-if="liveBoardRoom?.code"> ({{ liveBoardRoom.code }})</span>
        <span> • Updated {{ liveBoardLastUpdatedText }}</span>
      </p>

      <div v-if="liveBoardError" class="feedback danger">
        <AlertCircle :size="15" />
        <span>{{ liveBoardError }}</span>
      </div>

      <div class="live-board-toolbar">
        <label class="check-item">
          <input v-model="liveBoardOptions.show_names" type="checkbox" />
          <span>Show Names</span>
        </label>
        <label class="check-item">
          <input v-model="liveBoardOptions.show_responses" type="checkbox" />
          <span>Show Responses</span>
        </label>
        <label class="check-item">
          <input v-model="liveBoardOptions.show_results" type="checkbox" />
          <span>Show Results</span>
        </label>
        <button type="button" class="ghost-btn" :disabled="liveBoardLoading || liveBoardRefreshing" @click="loadLiveBoard(false)">
          <RefreshCw :size="14" :class="{ 'spin-soft': liveBoardLoading || liveBoardRefreshing }" />
          Refresh
        </button>
      </div>

      <div class="management-inline live-board-summary">
        <span class="pill neutral">{{ liveBoardSummary.students_total }} student(s)</span>
        <span class="pill navy">{{ liveBoardSummary.attempts_started }} started</span>
        <span class="pill success">{{ liveBoardSummary.attempts_submitted }} submitted</span>
      </div>

      <div v-if="liveBoardLoading" class="room-empty-state compact">
        <RefreshCw :size="30" class="spin-soft" />
        <h4>Loading Live Board</h4>
        <p>Fetching latest student responses.</p>
      </div>

      <div v-else-if="liveBoardRows.length === 0" class="room-empty-state compact">
        <FileText :size="34" />
        <h4>No Student Data Yet</h4>
        <p>Students will appear here when they are enrolled and start answering.</p>
      </div>

      <div v-else class="live-board-table-wrap">
        <table class="live-board-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Progress</th>
              <th v-for="item in liveBoardItemSummary" :key="`live-head-${item.item_number}`">
                {{ item.item_number }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, rowIndex) in liveBoardRows" :key="row.user.id">
              <td class="live-board-name-cell">
                <strong>{{ liveBoardDisplayName(row, rowIndex) }}</strong>
                <small v-if="liveBoardOptions.show_names && row.user.student_id" class="muted">
                  ID {{ row.user.student_id }}
                </small>
              </td>
              <td class="live-board-progress-cell">
                <span class="pill" :class="row.attempt?.status === 'submitted' ? 'success' : 'neutral'">
                  {{ liveBoardProgressLabel(row) }}
                </span>
              </td>
              <td
                v-for="item in row.items"
                :key="`live-cell-${row.user.id}-${item.item_number}`"
                class="live-board-answer-cell"
                :class="liveBoardCellClass(item)"
              >
                {{ liveBoardCellText(item) }}
              </td>
            </tr>
          </tbody>
          <tfoot v-if="liveBoardItemSummary.length > 0">
            <tr>
              <th colspan="2">Class Total</th>
              <th v-for="item in liveBoardItemSummary" :key="`live-total-${item.item_number}`">
                {{ liveBoardItemSummaryText(item) }}
              </th>
            </tr>
          </tfoot>
        </table>
      </div>
    </article>

    <template v-else>
      <div v-if="roomLoading && rooms.length === 0" class="surface-card rooms-empty-card">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading rooms</h4>
        <p>Please wait while we fetch your room list.</p>
      </div>

      <div v-else-if="rooms.length === 0" class="surface-card rooms-empty-card">
        <House :size="42" />
        <h4>{{ canCreateRooms ? 'Add a Room' : 'No Rooms Available' }}</h4>
        <p>
          {{
            canCreateRooms
              ? 'Create a room for your class and share the generated code with students.'
              : 'No rooms are available to manage yet.'
          }}
        </p>
        <button v-if="canCreateRooms" type="button" class="primary-btn rooms-join-btn" :disabled="roomLoading" @click="openCreateRoomModal">
          <Plus :size="16" />
          Add Room
        </button>
        <button v-if="archivedRooms.length > 0" type="button" class="ghost-btn rooms-archived-btn" @click="openArchivedRoomsModal">
          <Archive :size="14" />
          Archived Rooms ({{ archivedRooms.length }})
        </button>
      </div>

      <!-- Wherever this is a single column (900px and under) it shows the
           list and the open room one at a time: list screen, then the room
           with a way back. Stacked, a tapped room opened below all 30-odd
           room cards, out of sight. -->
      <div
        v-else
        class="rooms-grid"
        :class="{
          'is-pane-list': isCompact && compactPane === 'list',
          'is-pane-detail': isCompact && compactPane === 'detail',
          'is-tab-students': showRoomTabs && roomTab === 'students',
          'is-tab-exams': showRoomTabs && roomTab === 'exams',
        }"
      >
        <aside class="surface-card rooms-list-card">
          <div class="rooms-list-head">
            <span class="rooms-list-label">{{ roomCollectionLabel }}</span>
            <span class="rooms-count-badge">{{ rooms.length }}</span>
          </div>

          <button v-if="canCreateRooms" type="button" class="primary-btn rooms-join-btn" :disabled="roomLoading" @click="openCreateRoomModal">
            <Plus :size="15" />
            Add Room
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
              :class="{ active: highlightedRoomId === room.id }"
              @click="openRoomFromList(room.id)"
            >
              <div class="rooms-list-item-head">
                <strong>{{ room.name }}</strong>
                <ChevronRight :size="15" class="rooms-list-chevron" />
              </div>
              <p class="rooms-list-code">CODE: {{ room.code }}</p>
              <div class="rooms-list-meta">
                <span><Users :size="12" />{{ room.members_count ?? 0 }} members</span>
                <span v-if="room.joins_closed_at"><Lock :size="12" />Not accepting students</span>
              </div>
              <p v-if="room.creator?.name" class="rooms-list-by">By {{ room.creator.name }}</p>
            </button>

            <p v-if="filteredRooms.length === 0" class="muted rooms-list-empty">No rooms match "{{ roomSearchQuery }}".</p>
          </div>

          <button v-if="archivedRooms.length > 0" type="button" class="ghost-btn rooms-archived-btn" @click="openArchivedRoomsModal">
            <Archive :size="14" />
            Archived Rooms ({{ archivedRooms.length }})
          </button>
        </aside>

        <section class="room-detail-column">
          <button v-if="isCompact" type="button" class="ghost-btn rooms-back-btn" @click="backToRoomList">
            <ChevronLeft :size="16" />
            All rooms
          </button>

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
                  <span class="room-banner-pill"><Users :size="12" />{{ selectedRoom.members_count ?? selectedRoom.members.length }} members</span>
                </div>
                <!-- Off keeps the class already here and turns the code away
                     for anyone new (a code shared outside the class). -->
                <button
                  type="button"
                  role="switch"
                  class="room-join-switch"
                  :class="{ 'is-on': !selectedRoom.joins_closed_at }"
                  :aria-checked="(!selectedRoom.joins_closed_at).toString()"
                  :disabled="roomJoiningSaving || roomDetailsLoading"
                  @click="toggleRoomJoining"
                >
                  <span class="room-join-switch-track" aria-hidden="true"><span class="room-join-switch-thumb" /></span>
                  <span class="room-join-switch-label">Accepting new students</span>
                  <span class="room-join-switch-state">{{ selectedRoom.joins_closed_at ? 'Off' : 'On' }}</span>
                </button>
              </div>
              <div class="room-banner-actions">
                <button type="button" class="ghost-btn room-banner-action-btn" :disabled="roomLoading || roomDetailsLoading || roomExporting" @click="openRoomExportModal">
                  <Download :size="14" />
                  Export
                </button>
                <button type="button" class="ghost-btn room-banner-action-btn" :disabled="roomLoading || roomDetailsLoading" @click="openEditRoomModal">
                  <Pencil :size="14" />
                  Edit
                </button>
                <button type="button" class="ghost-btn room-banner-action-btn" :disabled="roomLoading || roomDetailsLoading" @click="openArchiveRoomModal">
                  <Archive :size="14" />
                  Archive
                </button>
                <button type="button" class="danger-btn room-banner-action-btn" :disabled="roomLoading || roomDetailsLoading" @click="openDeleteRoomModal">
                  <Trash2 :size="14" />
                  Delete
                </button>
              </div>
            </header>

            <!-- A small screen has room for one of these at a time. Stacked,
                 the class list sat underneath every exam the room has. -->
            <div v-if="showRoomTabs" class="rooms-pane-tabs" role="tablist" aria-label="Room sections">
              <button
                v-for="tab in roomTabs"
                :key="tab.key"
                type="button"
                role="tab"
                class="rooms-pane-tab"
                :class="{ active: roomTab === tab.key }"
                :aria-selected="(roomTab === tab.key).toString()"
                @click="roomTab = tab.key"
              >
                <component :is="tab.icon" :size="15" />
                {{ tab.label }}
                <span class="rooms-pane-tab-count">{{ tab.count }}</span>
              </button>
            </div>

            <article v-if="isManagementRole" class="surface-card rooms-exams-card">
              <div class="rooms-exams-head">
                <div>
                  <h5>Room Exams</h5>
                  <p class="muted">Manage and review exam sessions assigned to this room</p>
                </div>
                <button
                  v-if="selectedRoomArchivedExams.length > 0"
                  type="button"
                  class="rooms-link-btn"
                  @click="showArchivedExams = !showArchivedExams"
                >
                  {{ showArchivedExams ? 'Hide archived' : `View archived (${selectedRoomArchivedExams.length})` }}
                </button>
              </div>

              <p v-if="selectedRoomActiveExams.length === 0" class="muted empty-detail">No exams assigned to this room yet.</p>
              <div v-else class="rooms-exam-list">
                <article v-for="exam in selectedRoomActiveExams" :key="exam.id" class="rooms-exam-row">
                  <div class="rooms-exam-row-head">
                    <div>
                      <strong class="rooms-exam-title">{{ exam.title }}</strong>
                      <p class="rooms-exam-subtitle">
                        {{ exam.progress ?? '0 / 0 answered' }} • {{ examDeliveryModeLabel(exam.delivery_mode) }}
                      </p>
                    </div>
                    <div class="rooms-exam-row-actions">
                      <button
                        v-if="canViewExamResults"
                        type="button"
                        class="rooms-exam-btn"
                        :disabled="liveBoardLoading || liveBoardRefreshing"
                        @click="openRoomLiveBoard(exam)"
                      >
                        <BarChart3 :size="14" />
                        Live Board
                      </button>
                      <button
                        type="button"
                        class="rooms-exam-icon-btn"
                        title="Archive exam"
                        :disabled="roomLoading || roomDetailsLoading"
                        @click="handleArchiveRoomExam(exam)"
                      >
                        <Archive :size="14" />
                      </button>
                    </div>
                  </div>

                  <div class="rooms-exam-row-foot">
                    <span class="rooms-exam-window-fallback">
                      Schedule: {{ formatExamSchedule(exam.schedule_start_at ?? exam.scheduled_at, exam.schedule_end_at) }}
                    </span>

                    <!-- Room -> Exam, the mirror of the Exams page's room
                         chips: one click to see everywhere this exam runs. -->
                    <RouterLink
                      v-if="isManagementRole"
                      class="assignment-chip is-link"
                      :to="{ name: 'dashboard-exams', query: { exam: exam.id } }"
                      :title="`Open ${exam.title} on the Exams page`"
                    >
                      <FileText :size="12" />
                      Exam setup
                    </RouterLink>
                  </div>
                </article>
              </div>

              <div v-if="showArchivedExams && selectedRoomArchivedExams.length > 0" class="rooms-exam-list archived">
                <article v-for="exam in selectedRoomArchivedExams" :key="`archived-${exam.id}`" class="rooms-exam-row archived">
                  <div class="rooms-exam-row-head">
                    <div>
                      <strong class="rooms-exam-title">{{ exam.title }}</strong>
                      <p class="rooms-exam-subtitle">{{ exam.total_items }} items • {{ exam.duration_minutes }} mins</p>
                    </div>
                    <div class="rooms-exam-row-actions">
                      <button
                        type="button"
                        class="rooms-exam-icon-btn restore"
                        title="Restore exam"
                        :disabled="roomLoading || roomDetailsLoading"
                        @click="handleRestoreRoomExam(exam)"
                      >
                        <ArchiveRestore :size="14" />
                      </button>
                    </div>
                  </div>

                  <div class="rooms-exam-row-foot">
                    <span class="rooms-exam-window-fallback">
                      Archived {{ exam.room_archived_at ? new Date(exam.room_archived_at).toLocaleDateString() : 'from current room' }}
                    </span>
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
                  <!-- The name opens the student's profile, as it does in
                       the Students directory. The room goes along in the
                       address so the profile can lead back here. -->
                  <RouterLink
                    class="rooms-roster-profile-link"
                    :to="{ name: 'dashboard-student-profile', params: { studentId: member.id }, query: { room: selectedRoom.id } }"
                    :title="`Open the profile of ${member.name}`"
                  >
                    <strong>{{ member.name }}</strong>
                  </RouterLink>
                  <p v-if="member.student_id">Student ID: {{ member.student_id }}</p>
                  <p v-else>{{ member.email }}</p>
                </div>
                <button
                  v-if="canRemoveRoomMember(member)"
                  type="button"
                  class="member-kick-icon-btn"
                  :aria-label="`Remove ${member.name} from room`"
                  title="Remove from room"
                  :disabled="roomLoading || roomDetailsLoading"
                  @click="handleKickRoomMember(member)"
                >
                  <UserMinus :size="14" />
                </button>
              </div>
            </div>
          </article>

          <article v-if="isManagementRole" class="rooms-summary-card">
            <p class="rooms-summary-title">Room Overview</p>

            <div class="rooms-summary-row">
              <span>Active Exam Coverage</span>
              <strong>{{ roomExamCoveragePercent }}%</strong>
            </div>
            <div class="rooms-summary-bar">
              <div class="rooms-summary-bar-fill" :style="{ width: `${roomExamCoveragePercent}%` }" />
            </div>

            <div class="rooms-summary-stats">
              <div class="rooms-summary-stat">
                <strong>{{ selectedRoomActiveExams.length }}</strong>
                <span>Active</span>
              </div>
              <div class="rooms-summary-stat">
                <strong>{{ selectedRoomArchivedExams.length }}</strong>
                <span>Archived</span>
              </div>
            </div>
          </article>
        </aside>
      </div>
    </template>

    <teleport to="body">
      <div v-if="showCreateRoomModal && canCreateRooms" class="modal-backdrop" @click.self="closeCreateRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Add Room</h4>
            <button type="button" class="modal-close" @click="closeCreateRoomModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Create a room for your class and share the generated code with students.</p>

          <label class="field-stack">
            <span class="field-label">Room name</span>
            <input
              v-model="roomName"
              type="text"
              class="text-input"
              maxlength="255"
              placeholder="e.g. LIS 4A - Mock Exam"
            />
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeCreateRoomModal">Cancel</button>
            <button type="button" class="primary-btn" :disabled="roomLoading || !roomName.trim()" @click="handleCreateRoom">
              <Plus :size="16" />
              Create Room
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showEditRoomModal" class="modal-backdrop" @click.self="closeEditRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Edit Room</h4>
            <button type="button" class="modal-close" @click="closeEditRoomModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Update room name for <strong>{{ selectedRoom?.code }}</strong>.</p>

          <label class="field-stack">
            <span class="field-label">Room name</span>
            <input
              v-model="editRoomName"
              type="text"
              class="text-input"
              maxlength="255"
              placeholder="Enter updated room name"
            />
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeEditRoomModal">Cancel</button>
            <button type="button" class="primary-btn" :disabled="roomLoading || !editRoomName.trim()" @click="handleUpdateRoom">
              <Pencil :size="16" />
              Save Changes
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showRoomExportModal" class="modal-backdrop" @click.self="closeRoomExportModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Export Gradebook</h4>
            <button type="button" class="modal-close" @click="closeRoomExportModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            The Gradebook for <strong>{{ selectedRoom?.name }}</strong>
            <span v-if="selectedRoom?.code">(<code>{{ selectedRoom.code }}</code>)</span>:
            one row per student, with their latest score on every exam this room has had.
            For one exam in detail, use the Dashboard's Exports tab.
          </p>

          <label class="field-stack">
            <span class="field-label">Export Format</span>
            <select v-model="roomExportForm.format" class="text-input" :disabled="roomExporting">
              <option v-for="format in roomExportFormats" :key="format.value" :value="format.value">
                {{ format.label }}
              </option>
            </select>
            <small class="muted">{{ roomExportFormats.find((format) => format.value === roomExportForm.format)?.hint }}</small>
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomExporting" @click="closeRoomExportModal">Cancel</button>
            <button type="button" class="primary-btn" :disabled="roomExporting" @click="exportRoomGrades">
              <RefreshCw v-if="roomExporting" :size="16" class="spin-soft" />
              <Download v-else :size="16" />
              <span>{{ roomExporting ? 'Exporting...' : 'Export Gradebook' }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showDeleteRoomModal" class="modal-backdrop" @click.self="closeDeleteRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Delete Room</h4>
            <button type="button" class="modal-close" @click="closeDeleteRoomModal">
              <X :size="16" />
            </button>
          </header>

          <!-- Delete is for a room that was never used. One that has results,
               or an exam still ahead of its class, is told why and offered
               Archive, before the button is pressed rather than after. -->
          <template v-if="roomRemoval.canDelete">
            <p class="muted">
              Delete <strong>{{ selectedRoom?.name }}</strong> (<code>{{ selectedRoom?.code }}</code>)? This cannot be undone.
            </p>
            <p v-if="roomMemberCount > 0" class="muted">
              {{ roomMemberCount === 1 ? '1 student' : `${roomMemberCount} students` }} will be removed from it.
            </p>
          </template>

          <template v-else>
            <p class="muted">
              <strong>{{ selectedRoom?.name }}</strong> (<code>{{ selectedRoom?.code }}</code>) cannot be deleted:
            </p>
            <ul class="room-removal-reasons">
              <li v-if="roomRemoval.hasResults">Students have taken exams in it, and their results would be deleted with it.</li>
              <li v-if="roomRemoval.pendingExams.length > 0">
                {{ roomRemoval.pendingExams.length === 1 ? 'An exam is' : 'Exams are' }} still open or scheduled in it:
                <strong>{{ roomRemoval.pendingExams.join(', ') }}</strong>.
              </li>
            </ul>
            <p class="muted">
              {{
                roomRemoval.hasResults
                  ? 'Archive the room instead. It leaves your list and your students\' lists, and everything in it is kept.'
                  : 'Archive that exam from the room first, or archive the room, which keeps everything in it.'
              }}
            </p>
          </template>

          <!-- The page-level banner sits behind this dialog, so a refused
               delete has to be reported here to be seen at all. -->
          <div v-if="roomError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ roomError }}</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeDeleteRoomModal">Cancel</button>
            <button v-if="roomRemoval.canDelete" type="button" class="danger-btn" :disabled="roomLoading" @click="handleDeleteRoom">
              <Trash2 :size="16" />
              Delete Room
            </button>
            <button v-else type="button" class="primary-btn" :disabled="roomLoading" @click="openArchiveRoomModal">
              <Archive :size="16" />
              Archive Instead
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showArchiveRoomModal" class="modal-backdrop" @click.self="closeArchiveRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Archive Room</h4>
            <button type="button" class="modal-close" @click="closeArchiveRoomModal">
              <X :size="16" />
            </button>
          </header>

          <template v-if="roomRemoval.canArchive">
            <p class="muted">
              Archive <strong>{{ selectedRoom?.name }}</strong> (<code>{{ selectedRoom?.code }}</code>)? It leaves your
              list and your students' lists. Its students, exams and results are kept, and you can restore it at any time.
            </p>
            <p v-if="roomRemoval.pendingExams.length > 0" class="feedback info" role="note">
              <AlertCircle :size="15" />
              <span>
                Students will no longer be able to take
                <strong>{{ roomRemoval.pendingExams.join(', ') }}</strong> in this room while it is archived.
              </span>
            </p>
          </template>

          <p v-else class="muted">
            <strong>{{ selectedRoom?.name }}</strong> cannot be archived right now:
            {{ roomRemoval.attemptsInProgress === 1 ? '1 student is' : `${roomRemoval.attemptsInProgress} students are` }}
            in the middle of an exam in it. Try again once they have submitted.
          </p>

          <div v-if="roomError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ roomError }}</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeArchiveRoomModal">Cancel</button>
            <button v-if="roomRemoval.canArchive" type="button" class="primary-btn" :disabled="roomLoading" @click="handleArchiveRoom">
              <Archive :size="16" />
              Archive Room
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showArchivedRoomsModal" class="modal-backdrop" @click.self="closeArchivedRoomsModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Archived Rooms</h4>
            <button type="button" class="modal-close" @click="closeArchivedRoomsModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Restoring a room puts it back in your list and your students' lists, as it was.</p>

          <div v-if="roomError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ roomError }}</span>
          </div>

          <ul class="archived-room-list">
            <li v-for="room in archivedRooms" :key="room.id" class="archived-room">
              <span class="archived-room-copy">
                <strong>{{ room.name }}</strong>
                <span>
                  Code {{ room.code }} &middot; {{ archivedRoomStudents(room) }}
                  <template v-if="room.creator?.name && isAdminRole"> &middot; By {{ room.creator.name }}</template>
                </span>
              </span>
              <button type="button" class="ghost-btn" :disabled="restoringRoomId !== null" @click="handleRestoreRoom(room)">
                <RefreshCw v-if="restoringRoomId === room.id" :size="14" class="spin-soft" />
                <ArchiveRestore v-else :size="14" />
                Restore
              </button>
            </li>
          </ul>

          <p v-if="archivedRooms.length === 0" class="muted">No archived rooms.</p>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" @click="closeArchivedRoomsModal">Close</button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  AlertCircle,
  Archive,
  ArchiveRestore,
  BarChart3,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  CircleUserRound,
  DoorOpen,
  Download,
  FileText,
  House,
  Lock,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  UserMinus,
  Users,
  X,
} from 'lucide-vue-next'
import { examDeliveryModeLabel, formatExamSchedule } from '../composables/useDashboardFormatters'
import { useRoomsModule } from '../composables/useRoomsModule'
import { useCompactLayout } from '../composables/useViewport'

// Local, not composable state -- mirrors the same "reveal archived exams
// inline" toggle the student Rooms page uses (see RoomsView.vue), reset per
// page load like every other UI-only reveal on this view.
const showArchivedExams = ref(false)

// An old room's students have mostly graduated, and those are counted apart.
function archivedRoomStudents(room) {
  const count = Number(room.members_count ?? 0) + Number(room.archived_members_count ?? 0)

  return count === 1 ? '1 student' : `${count} students`
}

// How many students deleting the open room would take out of it.
const roomMemberCount = computed(() => Number(selectedRoom.value?.members_count ?? selectedRoom.value?.members?.length ?? 0))

const {
  isStaffRole,
  isManagementRole,
  canCreateRooms,
  canViewExamResults,
  roomName,
  roomSearchQuery,
  filteredRooms,
  rooms,
  selectedRoomId,
  selectedRoom,
  showCreateRoomModal,
  showEditRoomModal,
  showDeleteRoomModal,
  showArchiveRoomModal,
  showArchivedRoomsModal,
  archivedRooms,
  restoringRoomId,
  roomRemoval,
  openArchiveRoomModal,
  closeArchiveRoomModal,
  handleArchiveRoom,
  roomJoiningSaving,
  toggleRoomJoining,
  openArchivedRoomsModal,
  closeArchivedRoomsModal,
  handleRestoreRoom,
  isAdminRole,
  showRoomExportModal,
  editRoomName,
  roomLoading,
  roomDetailsLoading,
  roomExporting,
  roomError,
  roomMessage,
  roomExportFormats,
  roomExportForm,
  roomLiveBoardActive,
  liveBoardExam,
  liveBoardRoom,
  liveBoardRows,
  liveBoardItemSummary,
  liveBoardSummary,
  liveBoardLoading,
  liveBoardRefreshing,
  liveBoardError,
  liveBoardOptions,
  liveBoardLastUpdatedText,
  roomCollectionLabel,
  selectedRoomActiveExams,
  selectedRoomArchivedExams,
  selectedRoomCurrentMembers,
  selectedRoomYearSummary,
  selectedRoomRosterCopy,
  canRemoveRoomMember,
  openCreateRoomModal,
  closeCreateRoomModal,
  openEditRoomModal,
  closeEditRoomModal,
  openDeleteRoomModal,
  closeDeleteRoomModal,
  openRoomExportModal,
  closeRoomExportModal,
  selectRoom,
  handleCreateRoom,
  handleUpdateRoom,
  handleDeleteRoom,
  handleKickRoomMember,
  handleArchiveRoomExam,
  handleRestoreRoomExam,
  openRoomLiveBoard,
  closeRoomLiveBoard,
  loadLiveBoard,
  liveBoardDisplayName,
  liveBoardProgressLabel,
  liveBoardCellText,
  liveBoardCellClass,
  liveBoardItemSummaryText,
  exportRoomGrades,
} = useRoomsModule({ mode: 'management' })

const isCompact = useCompactLayout()
const route = useRoute()
const router = useRouter()

// The room named in the address, when there is one.
const roomIdInAddress = computed(() => {
  const roomId = Number(route.query?.room)
  return Number.isFinite(roomId) && roomId > 0 ? roomId : null
})

/*
 * One pane at a time wherever this page is a single column (900px and
 * under): the list of rooms, then the room that was tapped. From 641 to
 * 900px it used to be stacked instead, so the room opened underneath the
 * whole list -- some 3,500px down for an admin with thirty rooms -- and
 * nothing on screen moved when one was tapped.
 *
 * Which pane is showing is read from the address (?room=ID) rather than kept
 * in a ref, so the phone's own Back button returns to the list instead of
 * leaving the page, and a link to a room opens that room.
 */
const compactPane = computed(() => (
  roomIdInAddress.value !== null && roomIdInAddress.value === selectedRoomId.value ? 'detail' : 'list'
))

let openedFromList = false
let listScrollTop = 0

// Side by side, the highlighted card is the room showing next to it. On its
// own screen the list has no room showing, and the one that happens to be
// loaded first is not something the reader chose -- so there it marks the
// room they last opened, which is what they look for on the way back.
const lastOpenedRoomId = ref(null)
const highlightedRoomId = computed(() => (isCompact.value ? lastOpenedRoomId.value : selectedRoomId.value))

function openRoomFromList(roomId) {
  selectRoom(roomId)

  if (!isCompact.value) return

  openedFromList = true
  listScrollTop = window.scrollY
  router.push({ query: { ...route.query, room: String(roomId) } })
}

function withoutRoomInAddress() {
  const query = { ...route.query }
  delete query.room

  return { query }
}

function backToRoomList() {
  // Opened by a tap on this page: the list is the previous entry in history,
  // so going back keeps Back and Forward meaning what they say. Opened from
  // a link, there is no list behind it to go back to.
  if (openedFromList) {
    router.back()
    return
  }

  router.replace(withoutRoomInAddress())
}

// A room has two long lists, its students and its exams, so on a narrow
// screen they are two tabs.
const roomTab = ref('students')
const showRoomTabs = computed(() => isCompact.value && isManagementRole.value && Boolean(selectedRoom.value))
const roomTabs = computed(() => [
  { key: 'students', label: 'Students', icon: Users, count: selectedRoomCurrentMembers.value.length },
  { key: 'exams', label: 'Exams', icon: FileText, count: selectedRoomActiveExams.value.length },
])

// The top of the room on the way in; on the way out, back to where the list
// was left, so opening room 20 does not mean scrolling down to it again.
watch(compactPane, async (pane) => {
  if (!isCompact.value) return

  await nextTick()

  if (pane === 'detail') {
    lastOpenedRoomId.value = roomIdInAddress.value
    roomTab.value = 'students'
    window.scrollTo({ top: 0 })
    return
  }

  window.scrollTo({ top: openedFromList ? listScrollTop : 0 })
  openedFromList = false
})

// A room that changes under the open one (it was deleted, say) drops the
// address back to the list rather than quietly showing a different room.
watch(selectedRoomId, (roomId) => {
  if (!isCompact.value || roomIdInAddress.value === null) return
  if (roomId === roomIdInAddress.value) return

  router.replace(withoutRoomInAddress())
})

// Read-only "how much of this room's assigned exam list is still live" --
// same shape as the student page's completion-rate summary (a percent bar
// plus a two-stat row), but measured against exam archive state (a room-
// wide fact) instead of one student's attempt history, which management
// mode has no reason to track here.
const roomExamCoveragePercent = computed(() => {
  const total = selectedRoomActiveExams.value.length + selectedRoomArchivedExams.value.length
  if (total === 0) return 0
  return Math.round((selectedRoomActiveExams.value.length / total) * 100)
})
</script>

<style scoped src="./roomManagementView.css"></style>
