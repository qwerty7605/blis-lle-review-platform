<template>
  <section ref="pageRef" class="room-view">
    <div v-if="usersMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ usersMessage }}</span>
    </div>
    <div v-if="usersError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ usersError }}</span>
    </div>

    <article class="surface-card room-shell-card is-flush">
      <header class="room-page-head is-page-title">
        <div class="room-page-title">
          <UserRound :size="18" />
          <h3>Users</h3>
        </div>
        <!-- Narrow screens: the app bar already names the page, so this header
             hides and its actions move up into the bar. -->
        <Teleport to="#layout-topbar-actions" defer :disabled="!isCompact">
          <div class="management-actions">
            <!-- Once a year: graduate the finishing class and move everyone
                 else up. A page of its own, since it is a list to review. -->
            <RouterLink
              :to="{ name: 'dashboard-year-end' }"
              :class="isCompact ? 'appbar-icon-action' : 'ghost-btn'"
              :aria-label="isCompact ? 'End of school year' : undefined"
              :title="isCompact ? 'End of school year' : undefined"
            >
              <GraduationCap :size="isCompact ? 20 : 15" />
              <template v-if="!isCompact">End of School Year</template>
            </RouterLink>
            <button
              type="button"
              class="primary-btn"
              :class="isCompact ? 'appbar-action' : 'add-room-btn'"
              :aria-label="isCompact ? 'Add user' : undefined"
              @click="openCreateUserModal"
            >
              <Plus :size="16" />
              {{ isCompact ? 'Add' : 'Add User' }}
            </button>
          </div>
        </Teleport>
      </header>

      <div class="management-toolbar" :class="{ 'is-search-row': useRows }">
        <input
          v-model.trim="userFilters.search"
          type="text"
          class="text-input"
          inputmode="search"
          enterkeyhint="search"
          :placeholder="useRows ? 'Search name, email, ID' : 'Search name, email, or student ID'"
          aria-label="Search users"
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
          <span v-if="activeUserFilters.length > 0" class="filter-count">
            {{ activeUserFilters.length }}
            <span class="visually-hidden">active</span>
          </span>
        </button>
        <template v-else>
          <select
            v-for="field in userFilterFields"
            :key="field.key"
            v-model="userFilters[field.key]"
            class="text-input narrow"
            :aria-label="field.label"
          >
            <option v-for="option in field.options" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </template>
      </div>

      <div v-if="useRows && activeUserFilters.length > 0" v-scroll-hint class="filter-chip-row">
        <button
          v-for="chip in activeUserFilters"
          :key="chip.key"
          type="button"
          class="filter-chip"
          :aria-label="`Remove filter: ${chip.label}`"
          @click="clearUserFilter(chip.key)"
        >
          {{ chip.label }}
          <X :size="14" aria-hidden="true" />
        </button>
      </div>

      <div v-if="usersLoading && adminUsers.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading users</h4>
        <p>Fetching accounts and role assignments.</p>
      </div>

      <div v-else-if="adminUsers.length === 0" class="room-empty-state">
        <UserRound :size="42" />
        <h4>No users found</h4>
        <p>Adjust filters or create a new account.</p>
      </div>

      <div v-else class="user-directory-shell">
        <!-- Whole-directory totals: they ignore the search and filters on
             purpose (see the summary in routes/api/admin.php). -->
        <div class="user-directory-summary">
          <article
            v-for="card in userDirectorySummary"
            :key="card.key"
            class="user-directory-summary-card"
            :class="`is-${card.tone}`"
          >
            <span>{{ card.label }}</span>
            <strong>{{ formatCount(card.value) }}</strong>
          </article>
        </div>

        <section v-for="section in directorySections" :key="section.key" class="directory-section">
          <header class="directory-section-head">
            <div>
              <h4>{{ section.title }}</h4>
              <p>{{ section.description }}</p>
            </div>
            <!-- The per-year group cards used to carry these counts. -->
            <span class="pill" :class="section.countTone">{{ formatCount(sectionTotal(section)) }}</span>
          </header>

          <!--
            Where the table cannot fit, the list keeps the per-year groups:
            their headings stick to the app bar while you scroll, which is the
            only wayfinding a long list has on a small screen. One compact row
            per person, with everything you can do to them one tap away in the
            action sheet.
          -->
          <div v-if="useRows" class="directory-group-stack">
            <article
              v-for="group in section.groups"
              :key="group.key"
              class="directory-group-card"
              :class="{ archived: section.key === 'archived' }"
            >
              <div class="directory-group-head">
                <div>
                  <strong>{{ group.label }}</strong>
                  <p>{{ directoryGroupCountLabel(group, section.noun) }}</p>
                </div>
                <span class="pill" :class="section.countTone">{{ formatCount(group.total) }}</span>
              </div>

              <ul class="directory-row-list">
                <li v-for="user in group.users" :key="user.id">
                  <button
                    type="button"
                    class="directory-row"
                    aria-haspopup="dialog"
                    @click="openUserActions(user)"
                  >
                    <span class="directory-row-copy">
                      <strong>{{ user.name }}</strong>
                      <span>{{ userRowMeta(user) }}</span>
                    </span>
                    <span
                      v-if="!accountState(user).usable"
                      class="pill"
                      :class="accountState(user).tone"
                    >
                      {{ accountState(user).short }}
                    </span>
                    <Ellipsis :size="18" class="directory-row-more" aria-hidden="true" />
                  </button>
                </li>
              </ul>
            </article>
          </div>

          <!--
            Everything wider: the same directory table the Students screen
            uses, so the two user lists in this system read the same way. Year
            becomes a column rather than a heading, which is what replaced the
            per-year card stacks this used to render at this width.
          -->
          <div v-else class="directory-table-wrap">
            <table class="directory-table users-directory-table" :class="{ 'is-tight': isTightTable }">
              <thead>
                <tr>
                  <th scope="col">Name</th>
                  <!-- Role is a column only where it actually varies: in the
                       student sections every row would read "Student". -->
                  <th v-if="section.key === 'management'" scope="col">Role</th>
                  <template v-else>
                    <th scope="col">Student ID</th>
                    <th scope="col">Year</th>
                    <th scope="col">Status</th>
                  </template>
                  <th scope="col">Account</th>
                  <th scope="col">Email</th>
                  <th scope="col" class="directory-table-actions-col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="user in sectionRows(section)"
                  :key="user.id"
                  :class="{ 'is-archived': section.key === 'archived' }"
                >
                  <td class="directory-table-name-cell">
                    <strong>{{ user.name }}</strong>
                    <span v-if="section.key === 'archived' && user.archived_at" class="pill danger">
                      Archived {{ new Date(user.archived_at).toLocaleDateString() }}
                    </span>
                  </td>
                  <td v-if="section.key === 'management'">
                    <span class="pill neutral">{{ displayMemberRole(user.role) }}</span>
                  </td>
                  <template v-else>
                    <td>{{ user.student_id || '—' }}</td>
                    <td>
                      <span class="pill navy">{{ yearLevelShortLabel(user.year_level) }}</span>
                    </td>
                    <td>
                      <span
                        class="pill"
                        :class="user.student_status === 'enrolled' ? 'success' : 'neutral'"
                      >{{ studentStatusLabel(user.student_status) }}</span>
                    </td>
                  </template>
                  <td>
                    <!-- The short form ("Pending", not "Pending verification"):
                         this column is a status chip, not a sentence. -->
                    <span class="pill" :class="accountState(user).tone" :title="accountState(user).label">
                      {{ accountState(user).short }}
                    </span>
                  </td>
                  <td class="directory-table-email-cell" :title="user.email">{{ user.email }}</td>
                  <td>
                    <div class="management-actions compact directory-table-actions">
                      <!-- The words are hidden where the table is tight (see
                           .is-tight below), so each button carries its name
                           for a screen reader and as a tooltip as well. -->
                      <button
                        type="button"
                        class="ghost-btn"
                        :aria-label="`Edit ${user.name}`"
                        title="Edit"
                        @click="openEditUserModal(user)"
                      >
                        <Pencil :size="15" />
                        <span class="directory-action-label">Edit</span>
                      </button>
                      <!-- Only while the account is waiting on its emailed
                           link, which some people have no mailbox to get. -->
                      <button
                        v-if="!user.email_verified_at"
                        type="button"
                        class="ghost-btn"
                        :disabled="usersSaving"
                        :aria-label="`Verify the email of ${user.name}`"
                        title="Verify email"
                        @click="handleVerifyUserEmail(user)"
                      >
                        <MailCheck :size="15" />
                        <span class="directory-action-label">Verify</span>
                      </button>
                      <template v-if="section.key !== 'management'">
                        <button
                          type="button"
                          class="ghost-btn"
                          :disabled="usersSaving"
                          :aria-label="`${section.key === 'archived' ? 'Restore' : 'Archive'} ${user.name}`"
                          :title="section.key === 'archived' ? 'Restore' : 'Archive'"
                          @click="handleToggleUserArchive(user, section.key !== 'archived')"
                        >
                          <ArchiveRestore :size="15" />
                          <span class="directory-action-label">{{ section.key === 'archived' ? 'Restore' : 'Archive' }}</span>
                        </button>
                        <button
                          type="button"
                          class="ghost-btn"
                          :disabled="usersRecovering"
                          :aria-label="`Recover the account of ${user.name}`"
                          title="Recover account"
                          @click="openRecoverUserModal(user)"
                        >
                          <ShieldCheck :size="15" />
                          <span class="directory-action-label">Recover</span>
                        </button>
                      </template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

        </section>

        <div v-if="useRows" class="directory-load-more">
          <p class="muted">{{ loadedUsersLabel }}</p>
          <button
            v-if="canLoadMoreUsers"
            type="button"
            class="ghost-btn"
            :disabled="usersLoading"
            @click="loadMoreUsers"
          >
            <RefreshCw v-if="usersLoading" :size="14" class="spin-soft" />
            {{ usersLoading ? 'Loading…' : 'Load more' }}
          </button>
          <p v-else-if="usersLoadCapReached" class="muted">
            That's as far as this list goes. Search or use Filters to reach the rest.
          </p>
        </div>
      </div>

      <div v-if="!useRows && usersPagination.total > 0" class="management-toolbar users-pagination">
        <p class="muted">{{ usersRangeLabel }}</p>
        <div class="management-actions">
          <button
            type="button"
            class="ghost-btn"
            :disabled="usersLoading || !canGoToPreviousUsersPage"
            @click="loadAdminUsers({ page: usersPagination.current_page - 1 })"
          >
            Previous
          </button>
          <span class="pill neutral">
            Page {{ usersPagination.current_page }} of {{ usersPagination.last_page }}
          </span>
          <button
            type="button"
            class="ghost-btn"
            :disabled="usersLoading || !canGoToNextUsersPage"
            @click="loadAdminUsers({ page: usersPagination.current_page + 1 })"
          >
            Next
          </button>
        </div>
      </div>
    </article>

    <teleport to="body">
      <div v-if="showUserModal" class="modal-backdrop" @click.self="closeUserModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>{{ userForm.id ? 'Edit User' : 'Create User' }}</h4>
            <button type="button" class="modal-close" @click="closeUserModal">
              <X :size="16" />
            </button>
          </header>

          <div v-if="userDialogError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ userDialogError }}</span>
          </div>

          <label class="field-stack">
            <span class="field-label">Full Name</span>
            <input v-model.trim="userForm.name" type="text" class="text-input" maxlength="255" v-bind="userChecks.bind('name')" />
            <FieldError :checks="userChecks" field="name" />
          </label>

          <label class="field-stack">
            <span class="field-label">Email</span>
            <input v-model.trim="userForm.email" type="email" class="text-input" maxlength="255" v-bind="userChecks.bind('email')" />
            <FieldError :checks="userChecks" field="email" />
          </label>

          <!-- The role comes before the fields it decides: only a student has
               a student ID, year level, status or admission year. -->
          <div class="inline-form">
            <label class="field-stack grow">
              <span class="field-label">Role</span>
              <select v-model="userForm.role" class="text-input">
                <option value="student">Student</option>
                <option value="staff_master_examiner">Staff / Master Examiner</option>
                <option value="admin">Admin</option>
              </select>
            </label>
            <label class="check-item">
              <input v-model="userForm.is_active" type="checkbox" />
              <span>Account active</span>
            </label>
            <label
              v-if="userForm.id"
              class="check-item"
              title="Signing in needs a verified email. Tick this for someone who cannot open the emailed link."
            >
              <input v-model="userForm.email_verified" type="checkbox" />
              <span>Email verified</span>
            </label>
          </div>

          <div v-if="studentDetailsWillBeRemoved" class="feedback info" role="status">
            <AlertCircle :size="15" />
            <span>
              Saving makes this a staff account and removes its student details:
              <template v-if="editedUserBefore.student_id">student ID {{ editedUserBefore.student_id }}, </template>year level and student status.
            </span>
          </div>

          <template v-if="userForm.role === 'student'">
            <label class="field-stack">
              <span class="field-label">Student ID</span>
              <input
                v-model.trim="userForm.student_id"
                type="text"
                class="text-input"
                maxlength="10"
                inputmode="numeric"
                placeholder="e.g. 2300001"
                v-bind="userChecks.bind('student_id')"
              />
              <FieldError :checks="userChecks" field="student_id" />
            </label>

            <label class="field-stack">
              <span class="field-label">
                Year Level
                <InfoTip text="Required while enrolled. Levels past 4th exist for irregular students; leave it blank once they have graduated or left." />
              </span>
              <select v-model="userForm.year_level" class="text-input" v-bind="userChecks.bind('year_level')">
                <option value="">Not applicable</option>
                <option v-for="option in yearLevelOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
                <option v-for="level in [5, 6, 7, 8]" :key="level" :value="String(level)">
                  {{ level }}th Year (irregular)
                </option>
              </select>
              <FieldError :checks="userChecks" field="year_level" />
            </label>

            <div class="inline-form">
              <label class="field-stack grow">
                <span class="field-label">
                  Status
                  <InfoTip text="Anything other than Enrolled takes the student off class lists. Graduated and Dropped out are kept apart on purpose: a student who left never sat the board exam, so they are not a failure." />
                </span>
                <select v-model="userForm.student_status" class="text-input">
                  <option v-for="option in studentStatusOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </label>

              <label class="field-stack grow">
                <span class="field-label">
                  Admission Year
                  <InfoTip text="The batch they entered with. Fixed for life, unlike year level." />
                </span>
                <input
                  v-model="userForm.admission_year"
                  type="number"
                  class="text-input"
                  min="1900"
                  max="2999"
                  placeholder="e.g. 2026"
                  v-bind="userChecks.bind('admission_year')"
                />
                <FieldError :checks="userChecks" field="admission_year" />
              </label>
            </div>
          </template>

          <label class="field-stack">
            <span class="field-label">{{ userForm.id ? 'New Password (optional)' : 'Password' }}</span>
            <input v-model="userForm.password" type="password" class="text-input" autocomplete="new-password" v-bind="userChecks.bind('password')" />
            <FieldError :checks="userChecks" field="password" />
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="usersSaving" @click="closeUserModal">Cancel</button>
            <button
              type="button"
              class="primary-btn"
              :disabled="usersSaving"
              @click="handleSaveUser"
            >
              <RefreshCw v-if="usersSaving" :size="14" class="spin-soft" />
              <span>{{ usersSaving ? 'Saving...' : (userForm.id ? 'Update User' : 'Create User') }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showRecoverUserModal" class="modal-backdrop" @click.self="closeRecoverUserModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Recover Student Account</h4>
            <button type="button" class="modal-close" @click="closeRecoverUserModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            Recover access for <strong>{{ recoverTargetUser?.name }}</strong>
            (<code>{{ recoverTargetUser?.email }}</code>).
            This keeps historical exam data under the same account.
          </p>

          <div v-if="recoverDialogError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ recoverDialogError }}</span>
          </div>

          <label class="field-stack">
            <span class="field-label">Verify Student ID</span>
            <input
              v-model.trim="recoverForm.student_id"
              type="text"
              class="text-input"
              maxlength="10"
              inputmode="numeric"
              placeholder="Enter student ID on file"
              v-bind="recoverChecks.bind('student_id')"
            />
            <FieldError :checks="recoverChecks" field="student_id" />
          </label>

          <label class="field-stack">
            <span class="field-label">New Email</span>
            <input
              v-model.trim="recoverForm.email"
              type="email"
              class="text-input"
              maxlength="255"
              placeholder="new-email@example.com"
              v-bind="recoverChecks.bind('email')"
            />
            <FieldError :checks="recoverChecks" field="email" />
          </label>

          <label class="field-stack">
            <span class="field-label">Temporary Password</span>
            <input
              v-model="recoverForm.password"
              type="password"
              class="text-input"
              autocomplete="new-password"
              placeholder="At least 8 characters"
              v-bind="recoverChecks.bind('password')"
            />
            <FieldError :checks="recoverChecks" field="password" />
            <small class="muted">All active sessions will be revoked after recovery.</small>
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="usersRecovering" @click="closeRecoverUserModal">Cancel</button>
            <button
              type="button"
              class="primary-btn"
              :disabled="usersRecovering"
              @click="handleRecoverUserAccount"
            >
              <RefreshCw v-if="usersRecovering" :size="14" class="spin-soft" />
              <span>{{ usersRecovering ? 'Recovering...' : 'Recover Account' }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <BottomSheet v-if="useRows" v-model:open="filtersOpen" title="Filter users">
      <label v-for="field in userFilterFields" :key="field.key" class="field-stack">
        <span class="field-label">{{ field.label }}</span>
        <select v-model="userFilters[field.key]" class="text-input">
          <option v-for="option in field.options" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>

      <template #footer>
        <button
          type="button"
          class="ghost-btn"
          :disabled="activeUserFilters.length === 0"
          @click="resetUserFilters"
        >
          Reset
        </button>
        <button type="button" class="primary-btn" @click="filtersOpen = false">
          {{ filterSheetDoneLabel }}
        </button>
      </template>
    </BottomSheet>

    <BottomSheet
      v-model:open="userActionsOpen"
      :title="actionUser?.name ?? 'User'"
      :subtitle="actionUser?.email ?? ''"
      @closed="actionUser = null"
    >
      <template v-if="actionUser">
        <div class="management-inline user-action-tags">
          <span v-if="actionUser.student_id" class="pill neutral">ID {{ actionUser.student_id }}</span>
          <span v-if="isStudent(actionUser)" class="pill navy">{{ yearLevelLabel(actionUser.year_level) }}</span>
          <span v-else class="pill neutral">{{ displayMemberRole(actionUser.role) }}</span>
          <span v-if="isUserArchived(actionUser)" class="pill neutral">
            Archived · {{ studentStatusLabel(actionUser.student_status) }}
          </span>
          <span class="pill" :class="accountState(actionUser).tone">{{ accountState(actionUser).label }}</span>
        </div>

        <div class="user-action-list">
          <button type="button" class="user-action-item" @click="runUserAction(openEditUserModal)">
            <Pencil :size="18" />
            <span>Edit details</span>
          </button>
          <button
            v-if="!actionUser.email_verified_at"
            type="button"
            class="user-action-item"
            :disabled="usersSaving"
            @click="runUserAction(handleVerifyUserEmail)"
          >
            <MailCheck :size="18" />
            <span>Mark email as verified</span>
          </button>
          <template v-if="isStudent(actionUser)">
            <button
              type="button"
              class="user-action-item"
              :disabled="usersSaving"
              @click="runUserAction((user) => handleToggleUserArchive(user, !isUserArchived(user)))"
            >
              <ArchiveRestore :size="18" />
              <span>{{ isUserArchived(actionUser) ? 'Restore to current records' : 'Archive record' }}</span>
            </button>
            <button
              type="button"
              class="user-action-item"
              :disabled="usersRecovering"
              @click="runUserAction(openRecoverUserModal)"
            >
              <ShieldCheck :size="18" />
              <span>Recover account</span>
            </button>
          </template>
        </div>
      </template>
    </BottomSheet>
  </section>
</template>

<script setup>
import InfoTip from '@/components/InfoTip.vue'
import { computed, ref, watch } from 'vue'
import {
  AlertCircle,
  ArchiveRestore,
  CheckCircle2,
  Ellipsis,
  GraduationCap,
  MailCheck,
  Pencil,
  Plus,
  RefreshCw,
  ShieldCheck,
  SlidersHorizontal,
  UserRound,
  X,
} from 'lucide-vue-next'
import BottomSheet from '@/components/BottomSheet.vue'
import FieldError from '@/components/FieldError.vue'
import { useUsersModule } from '../composables/useUsersModule'
import { estimatePageWidth, useCompactLayout, useElementWidth, useMediaQuery } from '../composables/useViewport'
import { vScrollHint } from '@/utils/scrollHint'

const isCompact = useCompactLayout()

/*
 * Three layouts, chosen by the room the page really has (the sidebar takes
 * 264px of the window, or 84px collapsed):
 *
 *   under 950px     rows, each opening a sheet of its actions, as on a phone
 *   950 to 1139px   the table with its three row buttons as icons
 *   1140px and up   the table with the buttons named
 *
 * The table with named buttons is 1,094px wide. A 1366px laptop gives this
 * page 962px, and what did not fit was the last column: Edit, Archive and
 * Recover.
 *
 * The layout also decides how the list is fetched (rows grow with Load more,
 * the table pages), so it has to be right before the first request. The
 * page's width is not known until it is mounted; until then the layout goes
 * by the width of the area it is about to fill, or on the first page after a
 * reload by the window (1265px is 950px plus the open sidebar).
 */
const USERS_ROWS_BELOW = 950
const USERS_NAMED_BUTTONS_FROM = 1140

const pageRef = ref(null)
const pageWidth = useElementWidth(pageRef, estimatePageWidth())
const isNarrowWindow = useMediaQuery('(max-width: 1265px)')

const useRows = computed(() => (
  pageWidth.value > 0 ? pageWidth.value < USERS_ROWS_BELOW : isNarrowWindow.value
))
const isTightTable = computed(() => (
  !useRows.value && pageWidth.value > 0 && pageWidth.value < USERS_NAMED_BUTTONS_FROM
))

/**
 * Whether an account can actually be used, which needs BOTH gates open:
 * is_active is the switch staff control, email_verified_at is the holder
 * proving they can read the mailbox they signed up with.
 *
 * These were previously collapsed into one badge, so a registration with a
 * plausible but non-existent institutional address appeared as a green
 * "Active" 4th-year student while being unable to log in at all. Staff
 * counting enrolled students were counting phantoms.
 *
 * The usable state reads "Verified", the answer to "Pending": what moved the
 * account out of Pending is that its email was verified. `usable` is for the
 * code, so nothing has to compare against the wording.
 */
function accountState(user) {
  if (!user.is_active) return { label: 'Inactive', short: 'Inactive', tone: 'neutral', usable: false }
  if (!user.email_verified_at) return { label: 'Pending verification', short: 'Pending', tone: 'gold', usable: false }

  return { label: 'Verified', short: 'Verified', tone: 'success', usable: true }
}

function isStudent(user) {
  return String(user?.role ?? '').toLowerCase() === 'student'
}

/**
 * "1st" / "--" rather than "1st Year" / "Year level not set".
 *
 * The long form is right on a card, where it stands alone; in a table column
 * headed "Year" it just makes every row wider than its content needs. Matches
 * the Students screen, which already renders the short form.
 */
function yearLevelShortLabel(value) {
  const numeric = Number(value)
  if (!Number.isFinite(numeric) || numeric < 1) return '--'

  const suffixes = ['th', 'st', 'nd', 'rd']
  return `${numeric}${suffixes[numeric] ?? 'th'}`
}

function formatCount(value) {
  return Number(value ?? 0).toLocaleString()
}

/**
 * Every user in a section, flattened out of its per-year groups.
 *
 * The API still returns the directory grouped by year level (and the group
 * totals still drive the section headings), but once Year is a column of the
 * table those stacks were a second, redundant level of nesting around what is
 * really one list.
 */
function sectionRows(section) {
  return (section?.groups ?? []).flatMap((group) => group.users ?? [])
}

/** Whole-directory total for a section, not just the page being shown. */
function sectionTotal(section) {
  return (section?.groups ?? []).reduce((sum, group) => sum + Number(group.total ?? 0), 0)
}

const {
  adminUsers,
  usersLoading,
  usersSaving,
  usersRecovering,
  usersError,
  usersMessage,
  showUserModal,
  showRecoverUserModal,
  recoverTargetUser,
  userFilters,
  usersPagination,
  recoverForm,
  userForm,
  userChecks,
  recoverChecks,
  userDialogError,
  recoverDialogError,
  studentDetailsWillBeRemoved,
  editedUserBefore,
  canGoToPreviousUsersPage,
  canGoToNextUsersPage,
  usersRangeLabel,
  canLoadMoreUsers,
  usersLoadCapReached,
  loadedUsersLabel,
  directorySections,
  directoryGroupCountLabel,
  userDirectorySummary,
  userFilterFields,
  activeUserFilters,
  clearUserFilter,
  resetUserFilters,
  displayMemberRole,
  yearLevelLabel,
  studentStatusOptions,
  studentStatusLabel,
  yearLevelOptions,
  isUserArchived,
  openCreateUserModal,
  openEditUserModal,
  closeUserModal,
  openRecoverUserModal,
  closeRecoverUserModal,
  loadAdminUsers,
  loadMoreUsers,
  handleSaveUser,
  handleToggleUserArchive,
  handleVerifyUserEmail,
  handleRecoverUserAccount,
} = useUsersModule({ loadMoreMode: useRows })

const filtersOpen = ref(false)
const userActionsOpen = ref(false)
const actionUser = ref(null)

const filterSheetDoneLabel = computed(() => {
  if (usersLoading.value) return 'Updating…'

  const total = Number(usersPagination.total ?? 0)
  if (total < 1) return 'No matching users'

  return `Show ${formatCount(total)} user${total === 1 ? '' : 's'}`
})

// The second line of a phone row: enough to tell two people with the same
// name apart without opening them.
function userRowMeta(user) {
  if (!isStudent(user)) return user.email

  return [
    user.student_id,
    isUserArchived(user) ? studentStatusLabel(user.student_status) : null,
    user.email,
  ].filter(Boolean).join(' · ')
}

function openUserActions(user) {
  actionUser.value = user
  userActionsOpen.value = true
}

// The sheet closes first so the dialog or confirmation the action opens is
// the only thing on screen.
function runUserAction(action) {
  const user = actionUser.value
  userActionsOpen.value = false
  if (user) action(user)
}

// Sheets belong to the rows layout; leaving it closes them rather than
// leaving one to reopen the next time the page narrows.
watch(useRows, () => {
  filtersOpen.value = false
  userActionsOpen.value = false
})
</script>

<style scoped>
/* Sized by its columns. The shared table rule holds every table to 1080px;
   this one is narrower than that with its buttons as icons, and held to it
   the last column, the buttons, was the part off the screen. */
.users-directory-table {
  min-width: 0;
}

.users-directory-table .directory-table-email-cell {
  max-width: 220px;
}

/* Tight: the three buttons keep their icons and lose their words (each has
   its name as a tooltip and for a screen reader), the name column gives up
   30px and a long address is cut sooner. */
.users-directory-table.is-tight .directory-action-label {
  display: none;
}

.users-directory-table.is-tight .directory-table-actions .ghost-btn {
  width: 34px;
  padding: 0;
  justify-content: center;
}

.users-directory-table.is-tight .directory-table-name-cell {
  min-width: 190px;
}

.users-directory-table.is-tight .directory-table-email-cell {
  max-width: 180px;
}

/* The form's two side-by-side rows. Centred on each other, the shorter
   field floated halfway down the taller one's helper text; they start level
   instead, and the checkbox lines up with the box beside it. */
.modal-card .inline-form {
  align-items: flex-start;
  margin-top: 0;
}

.modal-card .inline-form > .check-item {
  align-self: flex-end;
  min-height: var(--control-height);
}

@media (pointer: coarse) {
  .users-directory-table .directory-table-actions .ghost-btn {
    height: 40px;
  }

  .users-directory-table.is-tight .directory-table-actions .ghost-btn {
    width: 40px;
  }
}
</style>
