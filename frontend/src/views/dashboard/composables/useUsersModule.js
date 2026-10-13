import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { useDashboardDataServices } from './useDashboardDataServices'
import { firstPasswordProblem, passwordIsValid } from '@/utils/password'
import { useLiveRefresh } from '@/utils/liveRefresh'

const USERS_DEFAULT_PAGE_SIZE = 200

// Phones show a short list that grows by this much per "Load more" instead of
// 200-row pages: 200 cards was ~56 screens of scrolling before Next.
const USERS_LOAD_MORE_STEP = 30
// The server's per_page ceiling (routes/api/admin.php). Past it, the reader
// is asked to search or filter rather than keep loading.
const USERS_LOAD_MORE_CAP = 250

const USER_FILTER_DEFAULTS = Object.freeze({
  role: '',
  year_level: '',
  archive_state: 'current',
  status: '',
})

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function displayMemberRole(role) {
  const normalized = String(role ?? '').toLowerCase()
  if (normalized === 'admin') return 'Administrator'
  if (normalized === 'staff_master_examiner') return 'Staff / Master Examiner'
  return 'Student'
}

function yearLevelLabel(value) {
  const numeric = Number(value)
  if (numeric === 1) return '1st Year'
  if (numeric === 2) return '2nd Year'
  if (numeric === 3) return '3rd Year'
  if (numeric === 4) return '4th Year'
  return 'Year level not set'
}

function compareUsersForDirectory(left, right) {
  const nameCompare = String(left?.name ?? '').localeCompare(String(right?.name ?? ''), undefined, {
    sensitivity: 'base',
  })

  if (nameCompare !== 0) {
    return nameCompare
  }

  const studentIdCompare = String(left?.student_id ?? '').localeCompare(String(right?.student_id ?? ''), undefined, {
    numeric: true,
    sensitivity: 'base',
  })

  if (studentIdCompare !== 0) {
    return studentIdCompare
  }

  return Number(left?.id ?? 0) - Number(right?.id ?? 0)
}

/**
 * @param {{ loadMoreMode?: import('vue').Ref<boolean> }} [options]
 *   loadMoreMode: when true (phones), the list is the first N matches and
 *   grows with loadMoreUsers(); otherwise it pages 200 at a time.
 */
export function useUsersModule(options = {}) {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const loadMoreMode = options.loadMoreMode ?? ref(false)

  const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
  const isAdminRole = computed(() => normalizedRole.value === 'admin')

  const adminUsers = ref([])
  // Directory-wide counts from the server. The list is paged, so counting
  // adminUsers would stop at the page size.
  const usersSummary = ref({
    current_students: 0,
    archived_students: 0,
    staff_and_admin: 0,
    inactive: 0,
  })
  // How many matches each directory heading holds across the whole filtered
  // list, so a heading can say "30 of 331 shown" instead of passing off the
  // loaded rows as the total.
  const groupTotals = ref({
    current: {},
    archived: {},
    staff_master_examiner: 0,
    admin: 0,
  })
  // Load-more mode: how many rows the list currently asks for.
  const loadedWindow = ref(USERS_LOAD_MORE_STEP)
  const usersLoading = ref(false)
  const usersSaving = ref(false)
  const usersRecovering = ref(false)
  const usersError = ref('')
  const usersMessage = ref('')
  const showUserModal = ref(false)
  const showRecoverUserModal = ref(false)
  const recoverTargetUser = ref(null)
  const userFilters = reactive({
    search: '',
    ...USER_FILTER_DEFAULTS,
  })
  const usersPagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: USERS_DEFAULT_PAGE_SIZE,
    total: 0,
    from: 0,
    to: 0,
  })
  const recoverForm = reactive({
    student_id: '',
    email: '',
    password: '',
  })
  const studentStatusOptions = [
    { value: 'enrolled', label: 'Enrolled' },
    { value: 'graduated', label: 'Graduated' },
    { value: 'dropped', label: 'Dropped out' },
    { value: 'transferred', label: 'Transferred' },
    { value: 'on_leave', label: 'On leave' },
    { value: 'archived_unspecified', label: 'Archived (reason not recorded)' },
  ]

  function studentStatusLabel(value) {
    return studentStatusOptions.find((option) => option.value === value)?.label ?? '--'
  }

  const userForm = reactive({
    id: null,
    name: '',
    email: '',
    student_id: '',
    role: 'student',
    year_level: '1',
    student_status: 'enrolled',
    admission_year: '',
    is_active: true,
    email_verified: true,
    archived: false,
    password: '',
  })

  const yearLevelOptions = [
    { value: '1', label: '1st Year' },
    { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' },
    { value: '4', label: '4th Year' },
  ]

  // One definition of the filter controls: inline selects on wide screens,
  // labelled fields in the filter sheet on phones.
  const userFilterFields = [
    {
      key: 'role',
      label: 'Role',
      chipPrefix: 'Role',
      options: [
        { value: '', label: 'All roles' },
        { value: 'student', label: 'Student' },
        { value: 'staff_master_examiner', label: 'Staff / Master Examiner' },
        { value: 'admin', label: 'Admin' },
      ],
    },
    {
      key: 'year_level',
      label: 'Year level',
      options: [{ value: '', label: 'All year levels' }, ...yearLevelOptions],
    },
    {
      key: 'archive_state',
      label: 'Records',
      options: [
        { value: 'current', label: 'Current records' },
        { value: 'archived', label: 'Archived records' },
        { value: 'all', label: 'All records' },
      ],
    },
    {
      key: 'status',
      label: 'Account status',
      chipPrefix: 'Status',
      options: [
        { value: '', label: 'All status' },
        { value: 'active', label: 'Active' },
        { value: 'inactive', label: 'Inactive' },
      ],
    },
  ]

  // Filters moved off their defaults, as removable chips.
  const activeUserFilters = computed(() => userFilterFields
    .filter((field) => userFilters[field.key] !== USER_FILTER_DEFAULTS[field.key])
    .map((field) => {
      const optionLabel = field.options.find((option) => option.value === userFilters[field.key])?.label
        ?? String(userFilters[field.key])

      return {
        key: field.key,
        label: field.chipPrefix ? `${field.chipPrefix}: ${optionLabel}` : optionLabel,
      }
    }))

  function clearUserFilter(key) {
    if (!(key in USER_FILTER_DEFAULTS)) return
    userFilters[key] = USER_FILTER_DEFAULTS[key]
  }

  function resetUserFilters() {
    Object.assign(userFilters, USER_FILTER_DEFAULTS)
  }

  let usersFilterSearchDebounce = null
  // Only the newest request may write to the list: with typing, filters and
  // "Load more" all refetching, a slow early response must not land last.
  let usersRequestToken = 0

  const canGoToPreviousUsersPage = computed(() => usersPagination.current_page > 1)
  const canGoToNextUsersPage = computed(() => usersPagination.current_page < usersPagination.last_page)
  const usersRangeLabel = computed(() => {
    if (!Number.isFinite(usersPagination.total) || usersPagination.total < 1) {
      return 'No users found'
    }

    const from = Number(usersPagination.from ?? 0)
    const to = Number(usersPagination.to ?? 0)
    const total = Number(usersPagination.total ?? 0)

    return `Showing ${from}-${to} of ${total} users`
  })
  const canLoadMoreUsers = computed(() => (
    loadMoreMode.value
    && adminUsers.value.length < usersPagination.total
    && loadedWindow.value < USERS_LOAD_MORE_CAP
  ))
  const usersLoadCapReached = computed(() => (
    loadMoreMode.value
    && adminUsers.value.length < usersPagination.total
    && loadedWindow.value >= USERS_LOAD_MORE_CAP
  ))
  const loadedUsersLabel = computed(() => {
    const total = Number(usersPagination.total ?? 0)
    if (!Number.isFinite(total) || total < 1) return 'No users found'

    return `Showing ${adminUsers.value.length.toLocaleString()} of ${total.toLocaleString()} user${total === 1 ? '' : 's'}`
  })
  const sortedAdminUsers = computed(() => (
    [...adminUsers.value].sort(compareUsersForDirectory)
  ))
  const currentStudentUsers = computed(() => (
    sortedAdminUsers.value.filter((user) => String(user?.role ?? '').toLowerCase() === 'student' && !isUserArchived(user))
  ))
  const archivedStudentUsers = computed(() => (
    sortedAdminUsers.value.filter((user) => String(user?.role ?? '').toLowerCase() === 'student' && isUserArchived(user))
  ))
  const managementUsers = computed(() => (
    sortedAdminUsers.value.filter((user) => String(user?.role ?? '').toLowerCase() !== 'student')
  ))
  // The server's count for a heading, never less than what is already loaded
  // (the two can briefly disagree while a newer response is in flight).
  function groupTotalFor(total, loadedCount) {
    const numeric = Number(total)
    return Number.isFinite(numeric) && numeric >= loadedCount ? numeric : loadedCount
  }

  function studentYearGroups(users, section, keyPrefix) {
    const groups = yearLevelOptions
      .map((option) => {
        const groupUsers = users.filter((user) => String(user?.year_level ?? '') === option.value)

        return {
          key: `${keyPrefix}${option.value}`,
          label: option.label,
          count: groupUsers.length,
          total: groupTotalFor(groupTotals.value?.[section]?.[option.value], groupUsers.length),
          users: groupUsers,
        }
      })
      .filter((group) => group.count > 0)

    const unassignedUsers = users.filter((user) => !['1', '2', '3', '4'].includes(String(user?.year_level ?? '')))

    if (unassignedUsers.length > 0) {
      groups.push({
        key: `${keyPrefix}unassigned`,
        label: 'Year Level Not Set',
        count: unassignedUsers.length,
        total: groupTotalFor(groupTotals.value?.[section]?.unassigned, unassignedUsers.length),
        users: unassignedUsers,
      })
    }

    return groups
  }

  const currentStudentYearGroups = computed(() => (
    studentYearGroups(currentStudentUsers.value, 'current', 'year-')
  ))
  const archivedStudentYearGroups = computed(() => (
    studentYearGroups(archivedStudentUsers.value, 'archived', 'archived-year-')
  ))
  const managementAccountGroups = computed(() => ([
    {
      key: 'staff-accounts',
      label: 'Staff / Master Examiners',
      role: 'staff_master_examiner',
    },
    {
      key: 'admin-accounts',
      label: 'Administrators',
      role: 'admin',
    },
  ]
    .map((group) => {
      const users = managementUsers.value.filter((user) => String(user?.role ?? '').toLowerCase() === group.role)

      return {
        key: group.key,
        label: group.label,
        count: users.length,
        total: groupTotalFor(groupTotals.value?.[group.role], users.length),
        users,
      }
    })
    .filter((group) => group.count > 0)))

  // The directory's sections in reading order. The server returns rows in
  // this same order, so a later page or "Load more" only ever continues the
  // last group on screen or starts the next one.
  const directorySections = computed(() => [
    {
      key: 'current',
      title: 'Current Students',
      description: 'Organized by year level for the active school records.',
      noun: 'student',
      countTone: 'navy',
      groups: currentStudentYearGroups.value,
    },
    {
      key: 'archived',
      title: 'Archived Student Records',
      description: 'Graduates and inactive academic records stay here for history and reporting.',
      noun: 'archived record',
      countTone: 'neutral',
      groups: archivedStudentYearGroups.value,
    },
    {
      key: 'management',
      title: 'Staff and Admin Accounts',
      description: 'Management accounts stay separate from the student directory.',
      noun: 'account',
      countTone: 'neutral',
      groups: managementAccountGroups.value,
    },
  ].filter((section) => section.groups.length > 0))

  function directoryGroupCountLabel(group, noun) {
    const counted = (count) => `${count.toLocaleString()} ${noun}${count === 1 ? '' : 's'}`

    if (group.count < group.total) {
      return `${group.count.toLocaleString()} of ${counted(group.total)} shown`
    }

    return counted(group.total)
  }
  // Every tile counts the whole directory, whatever page or filter the list
  // below is showing.
  const userDirectorySummary = computed(() => ([
    {
      key: 'current-students',
      label: 'Current Students',
      value: usersSummary.value.current_students,
      tone: 'success',
    },
    {
      key: 'archived-students',
      label: 'Archived Records',
      value: usersSummary.value.archived_students,
      tone: 'neutral',
    },
    {
      key: 'staff-admin',
      label: 'Staff and Admin',
      value: usersSummary.value.staff_and_admin,
      tone: 'navy',
    },
    {
      key: 'inactive',
      label: 'Inactive Accounts',
      value: usersSummary.value.inactive,
      tone: 'danger',
    },
  ]))

  function resetUserForm() {
    userForm.id = null
    userForm.name = ''
    userForm.email = ''
    userForm.student_id = ''
    userForm.role = 'student'
    userForm.year_level = '1'
    userForm.student_status = 'enrolled'
    userForm.admission_year = ''
    userForm.is_active = true
    userForm.email_verified = true
    userForm.archived = false
    userForm.password = ''
  }

  function openCreateUserModal() {
    resetUserForm()
    usersError.value = ''
    usersMessage.value = ''
    showUserModal.value = true
  }

  function openEditUserModal(user) {
    userForm.id = user.id
    userForm.name = user.name ?? ''
    userForm.email = user.email ?? ''
    userForm.student_id = user.student_id ?? ''
    userForm.role = user.role ?? 'student'
    userForm.year_level = user.year_level ? String(user.year_level) : ''
    userForm.student_status = user.student_status ?? 'enrolled'
    userForm.admission_year = user.admission_year ? String(user.admission_year) : ''
    userForm.is_active = Boolean(user.is_active)
    userForm.email_verified = Boolean(user.email_verified_at)
    userForm.archived = Boolean(user.archived_at)
    userForm.password = ''
    usersError.value = ''
    usersMessage.value = ''
    showUserModal.value = true
  }

  function closeUserModal() {
    showUserModal.value = false
    resetUserForm()
  }

  function resetRecoverForm() {
    recoverForm.student_id = ''
    recoverForm.email = ''
    recoverForm.password = ''
  }

  function openRecoverUserModal(user) {
    if (String(user?.role ?? '').toLowerCase() !== 'student') return

    recoverTargetUser.value = user
    resetRecoverForm()
    usersError.value = ''
    usersMessage.value = ''
    showRecoverUserModal.value = true
  }

  function closeRecoverUserModal() {
    showRecoverUserModal.value = false
    recoverTargetUser.value = null
    resetRecoverForm()
  }

  /**
   * Desktop: fetches one 200-row page. Load-more mode: always fetches the
   * first N rows, N growing with loadMoreUsers() -- refetching the whole
   * window means an edit or archive mid-list can never leave a gap or a
   * duplicate the way appending offset pages would.
   *
   * keepMessage leaves a success message from the action that triggered the
   * reload on screen; every reload used to wipe it the moment it was set.
   */
  // options.silent: the quiet refresh behind useLiveRefresh (below). Same
  // request for the same page and filters, but no loading state, no message
  // cleared, and a failure kept to itself.
  async function loadAdminUsers(options = {}) {
    if (!isAdminRole.value) return

    const silent = options.silent === true
    const token = ++usersRequestToken

    if (!silent) {
      usersLoading.value = true
      usersError.value = ''

      if (!options.keepMessage) {
        usersMessage.value = ''
      }
    }

    try {
      const requestedPage = Number(options.page ?? usersPagination.current_page ?? 1)
      const normalizedPage = Number.isFinite(requestedPage) && requestedPage > 0
        ? Math.trunc(requestedPage)
        : 1

      const params = loadMoreMode.value
        ? { page: 1, per_page: Math.min(loadedWindow.value, USERS_LOAD_MORE_CAP) }
        : { page: normalizedPage, per_page: USERS_DEFAULT_PAGE_SIZE }

      if (userFilters.search.trim()) params.search = userFilters.search.trim()
      if (userFilters.role) params.role = userFilters.role
      if (userFilters.status) params.status = userFilters.status
      if (userFilters.year_level) params.year_level = userFilters.year_level
      if (userFilters.archive_state) params.archive_state = userFilters.archive_state

      const { data } = await services.getUsers(params)
      if (token !== usersRequestToken) return

      adminUsers.value = data.users ?? []

      if (data?.summary) {
        usersSummary.value = {
          current_students: Number(data.summary.current_students ?? 0),
          archived_students: Number(data.summary.archived_students ?? 0),
          staff_and_admin: Number(data.summary.staff_and_admin ?? 0),
          inactive: Number(data.summary.inactive ?? 0),
        }
      }

      if (data?.group_totals) {
        groupTotals.value = {
          current: data.group_totals.current ?? {},
          archived: data.group_totals.archived ?? {},
          staff_master_examiner: Number(data.group_totals.staff_master_examiner ?? 0),
          admin: Number(data.group_totals.admin ?? 0),
        }
      }

      const meta = data?.meta ?? {}
      const currentPage = Number(meta.current_page ?? normalizedPage)
      const lastPage = Number(meta.last_page ?? 1)
      const perPage = Number(meta.per_page ?? params.per_page)
      const total = Number(meta.total ?? adminUsers.value.length)
      const fallbackFrom = total > 0 ? ((currentPage - 1) * perPage) + 1 : 0
      const fallbackTo = total > 0 ? Math.min(currentPage * perPage, total) : 0

      usersPagination.current_page = currentPage > 0 ? currentPage : normalizedPage
      usersPagination.last_page = lastPage > 0 ? lastPage : 1
      usersPagination.per_page = perPage > 0 ? perPage : USERS_DEFAULT_PAGE_SIZE
      usersPagination.total = Number.isFinite(total) && total >= 0 ? total : adminUsers.value.length
      usersPagination.from = Number(meta.from ?? fallbackFrom)
      usersPagination.to = Number(meta.to ?? fallbackTo)
    } catch (error) {
      if (token !== usersRequestToken || silent) return
      usersError.value = firstApiError(error, 'Unable to load users.')
    } finally {
      if (token === usersRequestToken && !silent) usersLoading.value = false
    }
  }

  // Read again when the admin comes back to this page, so an account that
  // registered meanwhile is in the list. Not under a dialog about one of the
  // rows.
  useLiveRefresh(() => loadAdminUsers({ silent: true }), {
    when: () => !usersLoading.value
      && !usersSaving.value
      && !usersRecovering.value
      && !showUserModal.value
      && !showRecoverUserModal.value,
  })

  function loadMoreUsers() {
    if (!canLoadMoreUsers.value || usersLoading.value) return

    loadedWindow.value = Math.min(loadedWindow.value + USERS_LOAD_MORE_STEP, USERS_LOAD_MORE_CAP)
    return loadAdminUsers({ page: 1 })
  }

  // A new search or filter starts again from the top of a short list.
  function reloadFromFirstPage() {
    loadedWindow.value = USERS_LOAD_MORE_STEP
    return loadAdminUsers({ page: 1 })
  }

  async function handleSaveUser() {
    if (!isAdminRole.value) return
    if (!userForm.name.trim() || !userForm.email.trim()) return

    if (userForm.role === 'student' && !/^\d{7,10}$/.test(userForm.student_id.trim())) {
      usersError.value = 'Student ID must be 7 to 10 digits for student accounts.'
      return
    }

    if (userForm.role === 'student' && !['1', '2', '3', '4'].includes(String(userForm.year_level))) {
      usersError.value = 'Select a valid year level for student accounts.'
      return
    }

    usersSaving.value = true
    usersError.value = ''
    usersMessage.value = ''

    const payload = {
      name: userForm.name.trim(),
      email: userForm.email.trim(),
      student_id: userForm.role === 'student' ? (userForm.student_id.trim() || null) : null,
      role: userForm.role,
      // Blank must travel as null, not Number('') === 0, which the year-level
      // range rule would reject.
      year_level: userForm.role === 'student' && userForm.year_level !== ''
        ? Number(userForm.year_level)
        : null,
      is_active: Boolean(userForm.is_active),
    }

    if (userForm.role === 'student') {
      // The status drives archived_at server-side, so it is the only signal
      // sent -- an "archived" flag alongside it could contradict it.
      payload.student_status = userForm.student_status
      payload.admission_year = userForm.admission_year === '' ? null : Number(userForm.admission_year)
    } else {
      payload.archived = false
    }

    if (userForm.password.trim()) {
      payload.password = userForm.password
    }

    try {
      if (userForm.id) {
        // A blank password on edit means "leave it alone"; only a typed one
        // has to satisfy the policy.
        const changeProblem = payload.password ? firstPasswordProblem(payload.password) : ''

        if (changeProblem) {
          usersError.value = changeProblem
          usersSaving.value = false
          return
        }

        const editedOwnAccount = Number(userForm.id) === Number(auth.user?.id)

        // Only on an edit: an account the admin creates is verified by being
        // created (the server sets that itself).
        payload.email_verified = Boolean(userForm.email_verified)

        await services.updateUser(userForm.id, payload)
        usersMessage.value = 'User updated successfully.'

        // The sidebar shows the signed-in account from the sign-in store; an
        // admin who renamed themselves saw the old name there until a reload.
        if (editedOwnAccount) await auth.fetchMe({ silent: true })
      } else {
        const passwordProblem = firstPasswordProblem(payload.password ?? '')

        if (passwordProblem) {
          usersError.value = passwordProblem
          usersSaving.value = false
          return
        }

        await services.createUser(payload)
        usersMessage.value = 'User created successfully.'
      }

      closeUserModal()
      await loadAdminUsers({ page: usersPagination.current_page, keepMessage: true })
    } catch (error) {
      usersError.value = firstApiError(error, 'Unable to save user.')
    } finally {
      usersSaving.value = false
    }
  }

  async function handleRecoverUserAccount() {
    if (!isAdminRole.value) return

    const targetUserId = Number(recoverTargetUser.value?.id ?? 0)
    if (!Number.isFinite(targetUserId) || targetUserId < 1) return

    if (!/^\d{7,10}$/.test(recoverForm.student_id.trim())) {
      usersError.value = 'Student ID must be 7 to 10 digits.'
      return
    }

    if (!recoverForm.email.trim() || !passwordIsValid(recoverForm.password)) return

    usersRecovering.value = true
    usersError.value = ''
    usersMessage.value = ''

    try {
      const { data } = await services.recoverUserAccount(targetUserId, {
        student_id: recoverForm.student_id.trim(),
        email: recoverForm.email.trim(),
        password: recoverForm.password,
      })

      usersMessage.value = data?.message ?? 'Student account recovered successfully.'
      closeRecoverUserModal()
      await loadAdminUsers({ page: usersPagination.current_page, keepMessage: true })
    } catch (error) {
      usersError.value = firstApiError(error, 'Unable to recover the student account.')
    } finally {
      usersRecovering.value = false
    }
  }

  function isUserArchived(user) {
    return Boolean(user?.archived_at)
  }

  async function handleToggleUserArchive(user, shouldArchive) {
    if (!isAdminRole.value) return
    if (String(user?.role ?? '').toLowerCase() !== 'student') return

    const userId = Number(user?.id ?? 0)
    if (!Number.isFinite(userId) || userId < 1) return

    const userName = String(user?.name ?? 'this student').trim() || 'this student'
    const actionLabel = shouldArchive ? 'archive' : 'restore'

    if (typeof window !== 'undefined') {
      const confirmed = window.confirm(`${shouldArchive ? 'Archive' : 'Restore'} ${userName}?`)
      if (!confirmed) return
    }

    usersSaving.value = true
    usersError.value = ''
    usersMessage.value = ''

    try {
      if (shouldArchive) {
        await services.archiveUser(userId)
        usersMessage.value = `${userName} moved to archived student records.`
      } else {
        await services.restoreUser(userId)
        usersMessage.value = `${userName} restored to current student records.`
      }

      await loadAdminUsers({ page: usersPagination.current_page, keepMessage: true })
    } catch (error) {
      usersError.value = firstApiError(error, `Unable to ${actionLabel} the student record.`)
    } finally {
      usersSaving.value = false
    }
  }

  /**
   * Lets an account sign in without the emailed link. For someone whose
   * address cannot receive it: no mailbox on the school's domain, or a link
   * that never arrived.
   */
  async function handleVerifyUserEmail(user) {
    if (!isAdminRole.value) return

    const userId = Number(user?.id ?? 0)
    if (!Number.isFinite(userId) || userId < 1) return

    const userName = String(user?.name ?? 'This account').trim() || 'This account'

    usersSaving.value = true
    usersError.value = ''
    usersMessage.value = ''

    try {
      await services.verifyUserEmail(userId)
      usersMessage.value = `${userName} is verified and can sign in now.`

      await loadAdminUsers({ page: usersPagination.current_page, keepMessage: true })
    } catch (error) {
      usersError.value = firstApiError(error, 'Unable to verify this account.')
    } finally {
      usersSaving.value = false
    }
  }

  watch(
    () => [userFilters.role, userFilters.status, userFilters.year_level, userFilters.archive_state],
    async () => {
      if (!isAdminRole.value) return
      await reloadFromFirstPage()
    },
  )

  watch(
    () => userFilters.search,
    (value, previousValue) => {
      if (!isAdminRole.value) return
      if (String(value ?? '') === String(previousValue ?? '')) return

      if (usersFilterSearchDebounce) {
        clearTimeout(usersFilterSearchDebounce)
        usersFilterSearchDebounce = null
      }

      usersFilterSearchDebounce = setTimeout(() => {
        if (!isAdminRole.value) return
        reloadFromFirstPage()
      }, 300)
    },
  )

  // Turning a phone to landscape past the breakpoint (or back) swaps paged
  // and load-more lists; either way the list restarts at the top.
  watch(loadMoreMode, () => {
    if (!isAdminRole.value) return
    reloadFromFirstPage()
  })

  onMounted(async () => {
    await loadAdminUsers({ page: 1 })
  })

  onBeforeUnmount(() => {
    if (!usersFilterSearchDebounce) return
    clearTimeout(usersFilterSearchDebounce)
    usersFilterSearchDebounce = null
  })

  return {
    studentStatusOptions,
    studentStatusLabel,
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
    canGoToPreviousUsersPage,
    canGoToNextUsersPage,
    usersRangeLabel,
    canLoadMoreUsers,
    usersLoadCapReached,
    loadedUsersLabel,
    currentStudentYearGroups,
    archivedStudentYearGroups,
    managementAccountGroups,
    directorySections,
    directoryGroupCountLabel,
    userDirectorySummary,
    userFilterFields,
    activeUserFilters,
    clearUserFilter,
    resetUserFilters,
    displayMemberRole,
    yearLevelLabel,
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
  }
}
