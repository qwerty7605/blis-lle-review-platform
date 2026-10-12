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
              </div>
              <p v-if="room.creator?.name" class="rooms-list-by">By {{ room.creator.name }}</p>
            </button>

            <p v-if="filteredRooms.length === 0" class="muted rooms-list-empty">No rooms match "{{ roomSearchQuery }}".</p>
          </div>
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

            <article v-if="isStaffRole" class="surface-card rooms-exams-card">
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
                  <strong>{{ member.name }}</strong>
                  <p v-if="member.student_id">Student ID: {{ member.student_id }}</p>
                  <p v-else>{{ member.email }}</p>
                </div>
                <span v-if="selectedRoomYearSummary.is_mixed" class="rooms-tag neutral small">
                  {{ member.year_level_label || yearLevelLabel(member.year_level) }}
                </span>
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

          <article v-if="isStaffRole" class="rooms-summary-card">
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
            <h4>Export Room Grades</h4>
            <button type="button" class="modal-close" @click="closeRoomExportModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            This export generates a room-level gradebook for <strong>{{ selectedRoom?.name }}</strong>
            <span v-if="selectedRoom?.code">(<code>{{ selectedRoom.code }}</code>)</span>.
            Use the Reports module for exam-specific detailed exports.
          </p>

          <label class="field-stack">
            <span class="field-label">Export Format</span>
            <select v-model="roomExportForm.format" class="text-input" :disabled="roomExporting">
              <option v-for="format in roomExportFormats" :key="format.value" :value="format.value">
                {{ format.label }}
              </option>
            </select>
            <small class="muted">Default format is XLSX for class record use.</small>
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

          <p class="muted">
            Delete <strong>{{ selectedRoom?.name }}</strong> (<code>{{ selectedRoom?.code }}</code>)?
            This will remove room enrollments.
          </p>

          <!-- The page-level banner sits behind this dialog, so a refused
               delete has to be reported here to be seen at all. -->
          <div v-if="roomError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ roomError }}</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeDeleteRoomModal">Cancel</button>
            <button type="button" class="danger-btn" :disabled="roomLoading" @click="handleDeleteRoom">
              <Trash2 :size="16" />
              Delete Room
            </button>
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

const {
  isStaffRole,
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
  yearLevelLabel,
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

// A teacher's room has two long lists, its students and its exams. An admin
// sees no exams here, so there is nothing to switch between.
const roomTab = ref('students')
const showRoomTabs = computed(() => isCompact.value && isStaffRole.value && Boolean(selectedRoom.value))
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

<style scoped src="../dashboard.css"></style>
<style scoped>
/*
  Mirrors RoomsView.vue's (student) redesign class-for-class so both roles
  see the same room-browsing layout, with staff-only actions layered on top
  (Add/Edit/Delete/Export in the banner, Archive/Restore/Live Board on each
  exam row, a Room Overview card instead of a per-student completion bar).
  Kept local rather than folded into the shared .room-list-panel/.detail-
  card/.exam-card family in dashboard.css -- those classes are reused by
  other pages too, so restyling them there would have changed those pages
  as well. Keeping this local avoids that entirely, same reasoning as the
  student page's own local style block.
*/

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

.room-banner-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  flex-shrink: 0;
}

.room-banner-action-btn {
  height: 34px;
  padding: 0 14px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}

/* Ghost buttons default to a navy-on-white look, which reads as nearly
   invisible against this banner's dark gradient -- a translucent white
   pill (matching .room-banner-pill's own treatment) keeps them legible
   without needing a one-off variant per button. The delete action stays
   plain .danger-btn red, which already has enough contrast here on its own. */
.room-banner-actions .ghost-btn.room-banner-action-btn {
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.28);
  color: #fff;
}

.room-banner-actions .ghost-btn.room-banner-action-btn:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.24);
  border-color: rgba(255, 255, 255, 0.4);
}

/* ---- Room exams ---- */
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

.rooms-exam-subtitle {
  margin: 3px 0 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.rooms-exam-row-actions {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.rooms-exam-row-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
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
  gap: 6px;
  justify-content: center;
  white-space: nowrap;
  background: linear-gradient(135deg, var(--lnu-navy), var(--lnu-navy-light));
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  transition: background 0.15s ease;
}

.rooms-exam-btn:hover:not(:disabled) {
  background: linear-gradient(135deg, var(--lnu-navy-light), var(--lnu-navy));
}

.rooms-exam-btn:disabled {
  background: #f4f5f8;
  border-color: rgba(26, 35, 126, 0.14);
  color: var(--lnu-text-muted);
  cursor: not-allowed;
}

.rooms-exam-icon-btn {
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  border-radius: 8px;
  border: 1px solid rgba(26, 35, 126, 0.16);
  background: #fff;
  color: var(--lnu-text-muted);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.rooms-exam-icon-btn:hover:not(:disabled) {
  background: rgba(26, 35, 126, 0.06);
  border-color: rgba(26, 35, 126, 0.28);
  color: var(--lnu-navy);
}

.rooms-exam-icon-btn.restore {
  border-color: rgba(15, 157, 120, 0.3);
  color: #0f9d78;
}

.rooms-exam-icon-btn.restore:hover:not(:disabled) {
  background: rgba(15, 157, 120, 0.08);
  border-color: rgba(15, 157, 120, 0.5);
}

.rooms-exam-icon-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.rooms-detail-loading,
.rooms-detail-empty {
  display: grid;
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

/* ---- Class list + room overview ---- */
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

.rooms-back-btn {
  display: none;
}

/* ---- Students / Exams switch inside a room (900px and under) ---- */
.rooms-pane-tabs {
  flex-shrink: 0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 4px;
  padding: 4px;
  border: 1px solid var(--border-soft);
  border-radius: 12px;
  background: var(--surface-muted);
}

.rooms-pane-tab {
  min-height: 40px;
  border: 0;
  border-radius: 9px;
  background: transparent;
  color: var(--ink-muted);
  font-size: 13.5px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}

.rooms-pane-tab.active {
  background: var(--surface);
  color: var(--lnu-navy);
  box-shadow: 0 1px 3px rgba(13, 21, 71, 0.14);
}

.rooms-pane-tab:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: -2px;
}

.rooms-pane-tab-count {
  min-width: 20px;
  padding: 1px 6px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.1);
  font-size: 11.5px;
  font-variant-numeric: tabular-nums;
}

@media (max-width: 1200px) {
  .rooms-grid {
    grid-template-columns: 220px minmax(0, 1fr);
  }

  .room-side-column {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

/*
 * Between 901px and 1200px the panels sit in two rows, and two rows cannot
 * share one screen: at 1024x768 the exam list was an 84px window and the
 * class list showed three students. Here the page stops pinning itself to
 * the screen height. Each list gets a workable window and the page scrolls
 * from the room down to its class list.
 *
 * The same on any wider screen that is short. A 1366x768 laptop shows a
 * page about 640px of height once the browser's own bars are counted, and
 * pinned to that the class list was a 221px window.
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
    max-height: 360px;
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
     widest unbreakable line, and a creator's name on a room card was enough
     to push the page 30-50px past the edge of a phone. */
  .rooms-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .room-side-column {
    grid-template-columns: minmax(0, 1fr);
  }

  /* One pane at a time -- see compactPane in the script. */
  .rooms-grid.is-pane-list .room-detail-column,
  .rooms-grid.is-pane-list .room-side-column,
  .rooms-grid.is-pane-detail .rooms-list-card {
    display: none;
  }

  /* ...and inside a teacher's room, one list at a time. The overview is
     about exams, so it keeps them company. */
  .rooms-grid.is-tab-students .rooms-exams-card,
  .rooms-grid.is-tab-students .rooms-summary-card,
  .rooms-grid.is-tab-exams .rooms-roster-card {
    display: none;
  }

  .rooms-back-btn {
    display: inline-flex;
    align-self: flex-start;
    gap: 4px;
    padding-left: 8px;
  }

  .room-banner {
    flex-direction: column;
    align-items: flex-start;
  }

  .room-banner-actions {
    width: 100%;
  }

  .room-banner-action-btn {
    flex: 1;
    justify-content: center;
  }
}

/* Tablets and phones turned sideways: the list has the whole width to
   itself now, so it runs two cards across and is half as long. */
@media (min-width: 641px) and (max-width: 900px) {
  .rooms-grid.is-pane-list .rooms-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-content: start;
  }
}

@media (max-width: 640px) {
  /* The title on a line of its own: beside the Live Board button it had a
     third of the row and broke into four lines. */
  .rooms-exam-row-head {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }

  .rooms-exam-row-actions .rooms-exam-btn {
    flex: 1;
  }

  /* Two-line rows: name, then code, members and owner on one line. */
  .rooms-list-item {
    min-height: 64px;
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    column-gap: 10px;
    row-gap: 2px;
    padding: 10px 12px;
  }

  .rooms-list-item-head {
    width: 100%;
  }

  .rooms-list-item strong {
    font-size: 15px;
  }

  .rooms-list-meta {
    margin-top: 0;
  }

  .rooms-list-by {
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }
}

@media (pointer: coarse) {
  .rooms-search {
    height: var(--control-height);
  }

  /* iOS Safari zooms the page into any field under 16px. The field fills
     the box around it, so a tap anywhere in the box lands in the field. */
  .rooms-search input {
    align-self: stretch;
    font-size: 16px;
  }

  .rooms-join-btn,
  .room-banner-action-btn {
    height: var(--control-height);
  }

  .rooms-pane-tab {
    min-height: 44px;
  }
}
</style>
