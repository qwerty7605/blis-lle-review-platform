import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { useDashboardDataServices } from './useDashboardDataServices'
import { useLiveRefresh } from '@/utils/liveRefresh'

// A page of the log at a time, newest first, with "Load more" for older
// entries -- the log used to be a fixed 80 entries with no way further back.
const AUDIT_PAGE_SIZE = 50

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function normalizeMeta(meta, fallbackPage) {
  return {
    current_page: Number(meta?.current_page ?? fallbackPage),
    last_page: Number(meta?.last_page ?? fallbackPage),
    total: Number(meta?.total ?? 0),
  }
}

export function useAuditModule() {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
  const isAdminRole = computed(() => normalizedRole.value === 'admin')

  const auditLogs = ref([])
  const auditMeta = ref({ current_page: 0, last_page: 1, total: 0 })
  const auditLoading = ref(false)
  const auditLoadingMore = ref(false)
  const auditError = ref('')

  // A refresh while "Load more" is in flight must win, not be appended to.
  let requestToken = 0

  const canLoadMoreAuditLogs = computed(() => (
    auditMeta.value.current_page > 0 && auditMeta.value.current_page < auditMeta.value.last_page
  ))

  const auditRangeLabel = computed(() => {
    const total = auditMeta.value.total
    if (!total) return ''

    return `Showing ${auditLogs.value.length.toLocaleString()} of ${total.toLocaleString()} entries`
  })

  async function loadAuditLogs() {
    if (!isAdminRole.value) return

    const token = ++requestToken
    auditLoading.value = true
    auditLoadingMore.value = false
    auditError.value = ''

    try {
      const { data } = await services.getAuditLogs({ page: 1, per_page: AUDIT_PAGE_SIZE })
      if (token !== requestToken) return

      auditLogs.value = data.logs ?? []
      auditMeta.value = normalizeMeta(data.meta, 1)
    } catch (error) {
      if (token !== requestToken) return
      auditError.value = firstApiError(error, 'Unable to load audit logs.')
    } finally {
      if (token === requestToken) auditLoading.value = false
    }
  }

  /*
   * The quiet refresh behind useLiveRefresh (below): the newest page is read
   * again and whatever is not on screen yet is put at the top. Older pages
   * the admin has already loaded stay where they are.
   */
  async function addNewAuditLogs() {
    if (!isAdminRole.value) return

    const token = requestToken
    const { data } = await services.getAuditLogs({ page: 1, per_page: AUDIT_PAGE_SIZE })

    // A load or a "load more" started meanwhile and owns the list now.
    if (token !== requestToken) return

    const shown = new Set(auditLogs.value.map((log) => log.id))
    const fresh = (data.logs ?? []).filter((log) => !shown.has(log.id))
    if (fresh.length === 0) return

    auditLogs.value = [...fresh, ...auditLogs.value]
    auditMeta.value = { ...auditMeta.value, total: Number(data.meta?.total ?? auditMeta.value.total) }
  }

  // The log grows while it is being read, so it is checked every minute as
  // well as when the admin comes back to it.
  useLiveRefresh(addNewAuditLogs, {
    every: 60000,
    when: () => !auditLoading.value && !auditLoadingMore.value,
  })

  async function loadMoreAuditLogs() {
    if (!isAdminRole.value || !canLoadMoreAuditLogs.value || auditLoading.value || auditLoadingMore.value) return

    const token = ++requestToken
    const nextPage = auditMeta.value.current_page + 1
    auditLoadingMore.value = true
    auditError.value = ''

    try {
      const { data } = await services.getAuditLogs({ page: nextPage, per_page: AUDIT_PAGE_SIZE })
      if (token !== requestToken) return

      // Entries logged since the first page push older ones down, so the next
      // page can start with a few already on screen.
      const shown = new Set(auditLogs.value.map((log) => log.id))
      auditLogs.value = [...auditLogs.value, ...(data.logs ?? []).filter((log) => !shown.has(log.id))]
      auditMeta.value = normalizeMeta(data.meta, nextPage)
    } catch (error) {
      if (token !== requestToken) return
      auditError.value = firstApiError(error, 'Unable to load older audit logs.')
    } finally {
      if (token === requestToken) auditLoadingMore.value = false
    }
  }

  onMounted(async () => {
    await loadAuditLogs()
  })

  return {
    auditLogs,
    auditLoading,
    auditLoadingMore,
    auditError,
    auditRangeLabel,
    canLoadMoreAuditLogs,
    loadAuditLogs,
    loadMoreAuditLogs,
  }
}
