import { computed, onMounted, reactive, ref, watch } from 'vue'
import { auditApi } from '@/api/audit.api'
import { useAuthStore } from '@/store/auth.store'
import { useLiveRefresh } from '@/utils/liveRefresh'

// A page of the log at a time, newest first, with "Load more" for older
// entries.
const AUDIT_PAGE_SIZE = 50

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

async function blobApiError(error, fallbackMessage) {
  const payload = error?.response?.data
  if (payload instanceof Blob) {
    try {
      return firstApiError({ response: { data: JSON.parse(await payload.text()) } }, fallbackMessage)
    } catch {
      return fallbackMessage
    }
  }
  return firstApiError(error, fallbackMessage)
}

function normalizeMeta(meta, fallbackPage) {
  return {
    current_page: Number(meta?.current_page ?? fallbackPage),
    last_page: Number(meta?.last_page ?? fallbackPage),
    total: Number(meta?.total ?? 0),
  }
}

// "2026-10-18" from a date input, as the first or last instant of that day
// where the reader is: the server keeps UTC, the reader means their own day.
function dayEdge(dateText, end) {
  if (!dateText) return undefined
  const [year, month, day] = dateText.split('-').map(Number)
  const at = end ? new Date(year, month - 1, day, 23, 59, 59, 999) : new Date(year, month - 1, day, 0, 0, 0, 0)
  return Number.isNaN(at.getTime()) ? undefined : at.toISOString()
}

function downloadFilename(response, fallbackName) {
  const header = String(response?.headers?.['content-disposition'] ?? '')
  const match = header.match(/filename\*=UTF-8''([^;]+)/i) ?? header.match(/filename="?([^";]+)"?/i)
  try {
    return match?.[1] ? decodeURIComponent(match[1]).replace(/"/g, '').trim() : fallbackName
  } catch {
    return fallbackName
  }
}

export const EMPTY_AUDIT_FILTERS = Object.freeze({
  q: '',
  category: '',
  outcome: '',
  from: '',
  to: '',
  actor_id: '',
  target_type: '',
  target_id: '',
})

export function useAuditModule() {
  const auth = useAuthStore()
  const isAdminRole = computed(() => String(auth.user?.role ?? '').toLowerCase() === 'admin')

  const auditLogs = ref([])
  const auditMeta = ref({ current_page: 0, last_page: 1, total: 0 })
  const auditLoading = ref(false)
  const auditLoadingMore = ref(false)
  const auditExporting = ref(false)
  const auditError = ref('')
  const categories = ref([])
  const filterNames = ref({ actor: null, target: null, target_type: null })

  const filters = reactive({ ...EMPTY_AUDIT_FILTERS })

  // A refresh while "Load more" is in flight must win, not be appended to.
  let requestToken = 0

  const activeFilterCount = computed(() => Object.entries(filters).filter(([key, value]) => key !== 'target_id' && value !== '').length)

  const canLoadMoreAuditLogs = computed(() => (
    auditMeta.value.current_page > 0 && auditMeta.value.current_page < auditMeta.value.last_page
  ))

  const auditRangeLabel = computed(() => {
    const total = auditMeta.value.total
    if (!total) return ''
    return `Showing ${auditLogs.value.length.toLocaleString()} of ${total.toLocaleString()} ${total === 1 ? 'entry' : 'entries'}`
  })

  function requestParams(page) {
    const params = { page, per_page: AUDIT_PAGE_SIZE }
    for (const key of ['q', 'category', 'outcome', 'actor_id', 'target_type', 'target_id']) {
      const value = String(filters[key] ?? '').trim()
      if (value !== '') params[key] = value
    }
    const from = dayEdge(filters.from, false)
    const to = dayEdge(filters.to, true)
    if (from) params.from = from
    if (to) params.to = to
    return params
  }

  function takeResponse(data) {
    if (Array.isArray(data.categories)) categories.value = data.categories
    filterNames.value = data.filter_names ?? { actor: null, target: null, target_type: null }
  }

  async function loadAuditLogs() {
    if (!isAdminRole.value) return

    const token = ++requestToken
    auditLoading.value = true
    auditLoadingMore.value = false
    auditError.value = ''

    try {
      const { data } = await auditApi.list(requestParams(1))
      if (token !== requestToken) return

      auditLogs.value = data.logs ?? []
      auditMeta.value = normalizeMeta(data.meta, 1)
      takeResponse(data)
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
    const { data } = await auditApi.list(requestParams(1))

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
      const { data } = await auditApi.list(requestParams(nextPage))
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

  async function exportAuditLogs() {
    if (!isAdminRole.value || auditExporting.value) return

    auditExporting.value = true
    auditError.value = ''

    try {
      const params = requestParams(1)
      delete params.page
      delete params.per_page
      params.tz = Intl.DateTimeFormat().resolvedOptions().timeZone

      const response = await auditApi.exportCsv(params)
      const url = window.URL.createObjectURL(response.data instanceof Blob ? response.data : new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.download = downloadFilename(response, 'blis-audit-log.csv')
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    } catch (error) {
      auditError.value = await blobApiError(error, 'Unable to export the audit log.')
    } finally {
      auditExporting.value = false
    }
  }

  function setFilter(changes) {
    Object.assign(filters, changes)
  }

  function clearFilters() {
    Object.assign(filters, EMPTY_AUDIT_FILTERS)
  }

  // Typing in the search waits for a pause; every other filter applies at once.
  let searchTimer = null
  watch(() => filters.q, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(loadAuditLogs, 350)
  })
  watch(
    () => [filters.category, filters.outcome, filters.from, filters.to, filters.actor_id, filters.target_type, filters.target_id],
    () => {
      clearTimeout(searchTimer)
      loadAuditLogs()
    },
  )

  onMounted(loadAuditLogs)

  return {
    auditLogs,
    auditMeta,
    auditLoading,
    auditLoadingMore,
    auditExporting,
    auditError,
    auditRangeLabel,
    canLoadMoreAuditLogs,
    categories,
    filterNames,
    filters,
    activeFilterCount,
    loadAuditLogs,
    loadMoreAuditLogs,
    exportAuditLogs,
    setFilter,
    clearFilters,
  }
}
