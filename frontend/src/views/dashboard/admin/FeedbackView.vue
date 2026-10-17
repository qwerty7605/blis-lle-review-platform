<template>
  <section class="room-view">
    <div v-if="listError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ listError }}</span>
    </div>
    <div v-if="pageMessage" class="feedback success" role="status">
      <CheckCircle2 :size="15" />
      <span>{{ pageMessage }}</span>
    </div>
    <div v-if="developerEmailSet === false" class="feedback danger">
      <AlertCircle :size="15" />
      <span>No developer email address is set on the server, so nothing can be sent to the developers yet.</span>
    </div>

    <article class="surface-card room-shell-card is-flush">
      <header class="room-page-head is-page-title">
        <div class="room-page-title">
          <MessageSquareText :size="18" />
          <h3>Feedback</h3>
        </div>
        <Teleport to="#layout-topbar-actions" defer :disabled="!isCompact">
          <button
            type="button"
            :class="isCompact ? 'appbar-icon-action' : 'ghost-btn'"
            :aria-label="isCompact ? 'Refresh feedback' : undefined"
            :disabled="loading"
            @click="load()"
          >
            <RefreshCw :size="isCompact ? 20 : 14" :class="{ 'spin-soft': loading }" />
            <template v-if="!isCompact">Refresh</template>
          </button>
        </Teleport>
      </header>

      <p class="fb-intro">
        Problems go to the developers as soon as they are reported. Ideas, questions and everything else wait here
        for you: answer them, close them, or send them on to the developers.
        <template v-if="developerEmail">
          <br />Developer email: <strong>{{ developerEmail }}</strong> (set on the server).
        </template>
      </p>

      <div v-scroll-hint class="fb-tabs" role="tablist" aria-label="Filter by status">
        <button
          v-for="tab in tabs"
          :key="tab.value"
          type="button"
          role="tab"
          class="fb-tab"
          :class="{ active: statusFilter === tab.value }"
          :aria-selected="statusFilter === tab.value"
          @click="setStatus(tab.value)"
        >
          {{ tab.label }}
          <span v-if="tab.count !== null" class="fb-tab-count">{{ tab.count }}</span>
        </button>
      </div>

      <div class="fb-toolbar">
        <label class="fb-type-filter">
          <span class="sr-only">Kind</span>
          <select v-model="typeFilter" class="text-input" @change="load()">
            <option value="">All kinds</option>
            <option v-for="type in feedbackTypes" :key="type.value" :value="type.value">{{ type.short }}</option>
          </select>
        </label>
        <p class="muted">{{ rangeLabel }}</p>
      </div>

      <div v-if="loading && items.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading feedback</h4>
      </div>

      <div v-else-if="items.length === 0" class="room-empty-state">
        <Inbox :size="42" />
        <h4>{{ statusFilter === 'new' ? 'Nothing waiting for you' : 'No feedback here' }}</h4>
        <p>Feedback appears here when someone sends it from their Settings page.</p>
      </div>

      <ul v-else class="fb-list">
        <li v-for="item in items" :key="item.id">
          <button type="button" class="fb-row" @click="openItem(item)">
            <span class="fb-row-icon" :class="`is-${item.type}`">
              <component :is="feedbackType(item.type).icon" :size="18" aria-hidden="true" />
            </span>
            <span class="fb-row-main">
              <span class="fb-row-message">{{ item.message }}</span>
              <span class="fb-row-meta">
                {{ item.user?.name ?? 'Removed account' }} · {{ roleLabel(item.user) }} · {{ formatDateTime(item.created_at) }}
              </span>
              <span class="fb-row-pills">
                <span class="pill neutral">{{ feedbackType(item.type).short }}</span>
                <span class="pill" :class="feedbackStatusTone(item.status)">{{ feedbackStatusLabel(item) }}</span>
                <span v-if="item.rating" class="pill neutral" :title="satisfactionLabels[item.rating - 1]">
                  <Star :size="12" aria-hidden="true" /> {{ item.rating }}/5
                </span>
                <span v-if="emailFailed(item)" class="pill danger">
                  Email not sent
                </span>
              </span>
            </span>
            <ChevronRight :size="18" class="fb-row-chevron" aria-hidden="true" />
          </button>
        </li>
      </ul>

      <div v-if="canLoadMore" class="directory-load-more">
        <button type="button" class="ghost-btn" :disabled="loadingMore" @click="loadMore">
          <RefreshCw v-if="loadingMore" :size="14" class="spin-soft" />
          {{ loadingMore ? 'Loading…' : 'Load older feedback' }}
        </button>
      </div>
    </article>

    <teleport to="body">
      <div v-if="selected" class="modal-backdrop" @click.self="closeItem">
        <div class="modal-card fb-dialog" role="dialog" aria-modal="true" aria-labelledby="fb-dialog-title">
          <header class="modal-head">
            <h4 id="fb-dialog-title">
              {{ feedbackType(selected.type).label }}
              <span class="pill" :class="feedbackStatusTone(selected.status)">{{ feedbackStatusLabel(selected) }}</span>
            </h4>
            <button type="button" class="modal-close" aria-label="Close" @click="closeItem">
              <X :size="16" />
            </button>
          </header>

          <div class="fb-dialog-body">
            <p class="fb-message">{{ selected.message }}</p>

            <dl class="fb-facts">
              <div>
                <dt>From</dt>
                <dd>{{ selected.user?.name ?? 'Removed account' }}<br /><small>{{ roleLabel(selected.user) }}</small></dd>
              </div>
              <div>
                <dt>Contact</dt>
                <dd>
                  <template v-if="selected.user?.email">{{ selected.user.email }}<br /></template>
                  <small>{{ selected.may_contact ? 'Agreed to be contacted' : 'Did not ask to be contacted' }}</small>
                </dd>
              </div>
              <div>
                <dt>Satisfaction</dt>
                <dd>{{ selected.rating ? `${selected.rating}/5, ${satisfactionLabels[selected.rating - 1]}` : 'Not given' }}</dd>
              </div>
              <div>
                <dt>Sent</dt>
                <dd>{{ formatDateTime(selected.created_at) }}</dd>
              </div>
              <div v-if="selected.context?.page">
                <dt>Page before Settings</dt>
                <dd>{{ selected.context.page }}</dd>
              </div>
              <div v-if="selected.context?.device || selected.context?.screen">
                <dt>Device</dt>
                <dd>{{ [selected.context.device, selected.context.screen].filter(Boolean).join(', ') }}</dd>
              </div>
            </dl>

            <p class="fb-trail">
              <template v-if="selected.developer_emailed_at">
                <Send :size="13" aria-hidden="true" /> Sent to the developers {{ formatDateTime(selected.developer_emailed_at) }}.
              </template>
              <template v-else-if="emailFailed(selected)">
                <AlertCircle :size="13" aria-hidden="true" /> The email to the developers did not go out: {{ selected.developer_email_error }}
              </template>
              <template v-else>Not sent to the developers.</template>
              <template v-if="selected.reviewer"> Last handled by {{ selected.reviewer.name }}, {{ formatDateTime(selected.reviewed_at) }}.</template>
            </p>

            <label class="field-stack">
              <span class="field-label">Reply to {{ firstName(selected.user) }} <em>(they see this under "Your Feedback")</em></span>
              <textarea v-model="reply" class="text-input textarea-input" rows="3" maxlength="3000" :placeholder="replyPlaceholder" />
            </label>

            <label v-if="canSend" class="field-stack">
              <span class="field-label">Note for the developers <em>(optional, only they see it)</em></span>
              <textarea
                v-model="developerNote"
                class="text-input textarea-input"
                rows="2"
                maxlength="3000"
                placeholder="Why it matters, or what you would like built."
              />
            </label>

            <div v-if="dialogError" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ dialogError }}</span></div>
          </div>

          <div class="modal-actions fb-actions">
            <button v-if="canReopen" type="button" class="ghost-btn" :disabled="saving" @click="decide('reopen')">
              <RotateCcw :size="14" /> Reopen
            </button>
            <template v-else>
              <button type="button" class="ghost-btn" :disabled="saving" @click="decide('decline')">
                {{ selected.type === 'problem' ? 'Not a Problem' : "Won't Do" }}
              </button>
              <button v-if="selected.type === 'problem' || selected.status === 'planned'" type="button" class="ghost-btn" :disabled="saving" @click="decide('done')">
                <CheckCircle2 :size="14" /> {{ selected.type === 'problem' ? 'Mark Fixed' : 'Mark Done' }}
              </button>
            </template>
            <button
              v-if="reply.trim() && reply.trim() !== (selected.admin_reply ?? '')"
              type="button"
              class="ghost-btn"
              :disabled="saving"
              @click="decide('answer')"
            >
              <MessageCircleReply :size="14" /> Send Reply
            </button>
            <button v-if="canSend" type="button" class="primary-btn" :disabled="saving || developerEmailSet === false" @click="decide('send_to_developers')">
              <RefreshCw v-if="saving" :size="14" class="spin-soft" />
              <Send v-else :size="14" />
              {{ emailFailed(selected) ? 'Try Sending Again' : 'Send to Developers' }}
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  AlertCircle,
  CheckCircle2,
  ChevronRight,
  Inbox,
  MessageCircleReply,
  MessageSquareText,
  RefreshCw,
  RotateCcw,
  Send,
  Star,
  X,
} from 'lucide-vue-next'
import { feedbackApi } from '@/api/feedback.api'
import { useLiveRefresh } from '@/utils/liveRefresh'
import {
  feedbackStatusLabel,
  feedbackStatusTone,
  feedbackType,
  feedbackTypes,
  satisfactionLabels,
} from '@/utils/feedbackLabels'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useCompactLayout } from '../composables/useViewport'

const PER_PAGE = 25

const isCompact = useCompactLayout()
const route = useRoute()
const router = useRouter()

const items = ref([])
const counts = ref({})
const meta = ref(null)
const developerEmailSet = ref(null)
const developerEmail = ref('')
const loading = ref(false)
const loadingMore = ref(false)
const listError = ref('')
const pageMessage = ref('')
const statusFilter = ref('new')
const typeFilter = ref('')

const selected = ref(null)
const reply = ref('')
const developerNote = ref('')
const saving = ref(false)
const dialogError = ref('')

const tabs = computed(() => {
  const count = (status) => counts.value?.[status] ?? 0
  const total = Object.values(counts.value ?? {}).reduce((sum, value) => sum + Number(value || 0), 0)

  return [
    { value: 'new', label: 'New', count: count('new') },
    { value: 'planned', label: 'With Developers', count: count('planned') },
    { value: 'answered', label: 'Answered', count: count('answered') },
    { value: 'done', label: 'Done', count: count('done') },
    { value: 'declined', label: 'Closed', count: count('declined') },
    { value: '', label: 'All', count: total },
  ]
})

const canLoadMore = computed(() => meta.value && meta.value.current_page < meta.value.last_page)

const rangeLabel = computed(() => {
  if (!meta.value || meta.value.total === 0) return ''

  return `Showing ${items.value.length} of ${meta.value.total}`
})

const canSend = computed(() => (
  selected.value
  && !selected.value.developer_emailed_at
  && !['done', 'declined'].includes(selected.value.status)
))

const canReopen = computed(() => ['done', 'declined', 'answered'].includes(selected.value?.status))

const replyPlaceholder = computed(() => (
  selected.value?.type === 'question'
    ? 'Your answer.'
    : 'Optional: thank them, or say why it will not be done.'
))

// Meant for the developers (a problem, or one already sent on) and not there.
function emailFailed(item) {
  return Boolean(item && !item.developer_emailed_at && item.developer_email_error && (item.type === 'problem' || item.status === 'planned'))
}

function roleLabel(user) {
  if (!user) return 'Account removed'
  if (user.role === 'student') return user.year_level ? `Student, year ${user.year_level}` : 'Student'
  if (user.role === 'admin') return 'Administrator'

  return 'Teacher'
}

function firstName(user) {
  return String(user?.name ?? 'the sender').split(' ')[0]
}

async function load({ silent = false } = {}) {
  if (!silent) {
    loading.value = true
    listError.value = ''
  }

  try {
    const { data } = await feedbackApi.list({
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      per_page: Math.max(PER_PAGE, silent ? items.value.length : 0),
    })

    items.value = data?.feedback ?? []
    counts.value = data?.counts ?? {}
    meta.value = data?.meta ?? null
    developerEmailSet.value = data?.developer_email_set ?? null
    developerEmail.value = data?.developer_email ?? ''
  } catch (error) {
    if (!silent) listError.value = error?.response?.data?.message ?? 'Unable to load feedback.'
  } finally {
    if (!silent) loading.value = false
  }
}

async function loadMore() {
  if (!canLoadMore.value) return

  loadingMore.value = true

  try {
    const { data } = await feedbackApi.list({
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      per_page: PER_PAGE,
      page: meta.value.current_page + 1,
    })

    const known = new Set(items.value.map((item) => item.id))
    items.value = [...items.value, ...(data?.feedback ?? []).filter((item) => !known.has(item.id))]
    meta.value = data?.meta ?? meta.value
  } catch (error) {
    listError.value = error?.response?.data?.message ?? 'Unable to load more feedback.'
  } finally {
    loadingMore.value = false
  }
}

function setStatus(value) {
  statusFilter.value = value
  pageMessage.value = ''
  load()
}

function openItem(item) {
  selected.value = item
  reply.value = item.admin_reply ?? ''
  developerNote.value = item.developer_note ?? ''
  dialogError.value = ''

  if (String(route.query.item ?? '') !== String(item.id)) {
    router.replace({ query: { ...route.query, item: item.id } })
  }
}

function closeItem() {
  selected.value = null

  if (route.query.item) {
    const { item, ...rest } = route.query
    router.replace({ query: rest })
  }
}

async function decide(action) {
  if (!selected.value) return

  saving.value = true
  dialogError.value = ''

  try {
    const { data } = await feedbackApi.decide(selected.value.id, {
      action,
      reply: reply.value.trim() || null,
      developer_note: action === 'send_to_developers' ? developerNote.value.trim() || null : undefined,
    })

    pageMessage.value = data?.message ?? 'Saved.'

    if (data?.email_sent === false) {
      // Keep the dialog open on the item so it can be tried again.
      selected.value = data.feedback
      dialogError.value = data.message
    } else {
      closeItem()
    }

    await load({ silent: true })
  } catch (error) {
    dialogError.value = error?.response?.data?.message ?? 'Unable to save.'
  } finally {
    saving.value = false
  }
}

// The developers' email links here with ?item=, which may be in any tab.
async function openFromQuery() {
  const id = Number(route.query.item)
  if (!id) return

  const listed = items.value.find((item) => item.id === id)
  if (listed) {
    openItem(listed)
    return
  }

  try {
    const { data } = await feedbackApi.get(id)
    if (data?.feedback) openItem(data.feedback)
  } catch {
    listError.value = 'That feedback could not be found.'
  }
}

onMounted(async () => {
  await load()
  await openFromQuery()
})

// New feedback arrives while the page is open.
useLiveRefresh(() => load({ silent: true }), {
  every: 60000,
  when: () => !selected.value && !loading.value && !loadingMore.value,
})
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.fb-intro {
  margin: 0;
  padding: 0 20px 12px;
  font-size: 13px;
  line-height: 1.5;
  color: var(--ink-soft);
}

.fb-tabs {
  display: flex;
  gap: 4px;
  overflow-x: auto;
  padding: 0 16px;
  border-bottom: 1px solid var(--border-soft);
  scrollbar-width: none;
}

.fb-tabs::-webkit-scrollbar {
  display: none;
}

.fb-tab {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 42px;
  padding: 0 12px;
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: var(--ink-soft);
  font: inherit;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
}

.fb-tab:hover {
  color: var(--lnu-navy-deep);
}

.fb-tab.active {
  border-bottom-color: var(--lnu-navy);
  color: var(--lnu-navy-deep);
}

.fb-tab-count {
  min-width: 20px;
  padding: 1px 6px;
  border-radius: 999px;
  background: var(--surface-muted);
  font-size: 11.5px;
  font-weight: 700;
  text-align: center;
}

.fb-tab.active .fb-tab-count {
  background: var(--lnu-navy);
  color: #ffffff;
}

.fb-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 16px;
}

.fb-toolbar .muted {
  margin: 0;
  font-size: 12.5px;
}

.fb-type-filter select {
  min-width: 150px;
}

.fb-list {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--border-soft);
}

.fb-list > li + li {
  border-top: 1px solid var(--border-soft);
}

.fb-row {
  width: 100%;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.fb-row:hover {
  background: rgba(26, 35, 126, 0.04);
}

.fb-row:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: -2px;
}

.fb-row-icon {
  flex: none;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: var(--surface-muted);
  color: var(--lnu-navy-deep);
}

.fb-row-icon.is-problem {
  background: rgba(201, 138, 30, 0.14);
  color: #8a5a0b;
}

.fb-row-main {
  flex: 1 1 0;
  min-width: 0;
  display: grid;
  gap: 4px;
}

.fb-row-message {
  font-size: 14px;
  font-weight: 600;
  line-height: 1.45;
  color: var(--lnu-navy-deep);
  overflow-wrap: anywhere;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.fb-row-meta {
  font-size: 12.5px;
  color: var(--ink-soft);
  overflow-wrap: anywhere;
}

.fb-row-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 2px;
}

.fb-row-pills .pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.fb-row-chevron {
  flex: none;
  align-self: center;
  color: var(--ink-soft);
}

.fb-dialog {
  width: min(640px, 100%);
}

.fb-dialog .modal-head h4 {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.fb-dialog-body {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 14px;
}

.fb-message {
  margin: 0;
  padding: 12px 14px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  background: var(--surface-muted);
  font-size: 14px;
  line-height: 1.55;
  white-space: pre-line;
  overflow-wrap: anywhere;
}

.fb-facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 16px;
  margin: 0;
}

.fb-facts dt {
  font-size: 11.5px;
  font-weight: 600;
  color: var(--ink-soft);
}

.fb-facts dd {
  margin: 2px 0 0;
  font-size: 13.5px;
  color: var(--lnu-navy-deep);
  overflow-wrap: anywhere;
}

.fb-facts small {
  color: var(--ink-soft);
}

.fb-trail {
  margin: 0;
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--ink-soft);
}

.fb-trail svg {
  vertical-align: -2px;
}

.field-label em {
  font-style: normal;
  font-weight: 400;
  color: var(--ink-soft);
}

.fb-actions {
  flex-wrap: wrap;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}

@media (max-width: 640px) {
  .fb-intro {
    padding: 12px 16px;
  }

  .fb-facts {
    grid-template-columns: minmax(0, 1fr);
  }

  .fb-actions > button {
    flex: 1 1 auto;
    justify-content: center;
  }
}
</style>
