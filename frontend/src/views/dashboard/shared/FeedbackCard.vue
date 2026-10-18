<template>
  <article class="surface-card account-card">
    <header class="account-card-head">
      <h3>Send Feedback <InfoTip text="Report a problem, suggest an improvement or ask a question. Every message is read." /></h3>
    </header>

    <form class="account-form" novalidate @submit.prevent="sendFeedback">
      <fieldset class="feedback-fieldset">
        <legend class="field-label">
          What is it about?
          <InfoTip text="A problem goes straight to the developers and to the administrator, so it can be fixed quickly. Anything else is read by the administrator, the head of BLIS, who decides whether it goes to the developers." label="Where your feedback goes" />
        </legend>
        <div class="feedback-types" role="radiogroup">
          <label v-for="type in feedbackTypes" :key="type.value" class="feedback-type" :class="{ selected: form.type === type.value }">
            <input v-model="form.type" type="radio" name="feedback-type" :value="type.value" />
            <component :is="type.icon" :size="18" aria-hidden="true" />
            <span class="feedback-type-copy">
              <strong>{{ type.label }}</strong>
              <small>{{ type.hint }}</small>
            </span>
          </label>
        </div>
      </fieldset>

      <fieldset class="feedback-fieldset">
        <legend class="field-label">How satisfied are you with BLIS overall? <em>(optional)</em></legend>
        <div class="feedback-scale" role="radiogroup">
          <label
            v-for="(label, index) in satisfactionLabels"
            :key="label"
            class="feedback-scale-option"
            :class="{ selected: form.rating === index + 1 }"
            :title="label"
          >
            <input
              type="radio"
              name="feedback-rating"
              :value="index + 1"
              :checked="form.rating === index + 1"
              :aria-label="`${index + 1}, ${label}`"
              @click="toggleRating(index + 1)"
            />
            <span>{{ index + 1 }}</span>
          </label>
        </div>
        <div class="feedback-scale-ends" aria-hidden="true">
          <span>{{ satisfactionLabels[0] }}</span>
          <span v-if="form.rating" class="feedback-scale-chosen">{{ satisfactionLabels[form.rating - 1] }}</span>
          <span>{{ satisfactionLabels[4] }}</span>
        </div>
      </fieldset>

      <label class="field-stack">
        <span class="field-label">Your Feedback</span>
        <textarea
          v-model="form.message"
          class="text-input textarea-input"
          rows="5"
          maxlength="5000"
          :placeholder="placeholder"
        />
        <span class="feedback-count" :class="{ short: tooShort }">
          {{ tooShort ? `At least 10 characters (${form.message.trim().length} so far)` : `${form.message.length} / 5000` }}
        </span>
        <span v-if="errors.message" class="account-field-error" role="alert">{{ errors.message }}</span>
      </label>

      <label class="check-item feedback-contact">
        <input v-model="form.may_contact" type="checkbox" />
        <span>You may contact me about this at <strong>{{ auth.user?.email }}</strong></span>
      </label>

      <!-- What is sent with it stays on the page; where it goes is behind the (i) by the type. -->
      <div class="feedback-route">
        <Info :size="16" aria-hidden="true" />
        <div>
          <p class="feedback-route-small">
            Sent with it: the page you came from, your browser and device, your screen size and the app version.
            {{ form.may_contact ? 'Your name and email are shared with the developers.' : 'The developers do not see your name or email.' }}
          </p>
        </div>
      </div>

      <div v-if="message" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ message }}</span></div>
      <div v-if="error" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ error }}</span></div>

      <div class="account-actions">
        <button type="submit" class="primary-btn" :disabled="sending || tooShort">
          <RefreshCw v-if="sending" :size="14" class="spin-soft" />
          <Send v-else :size="14" />
          <span>{{ sending ? 'Sending...' : 'Send Feedback' }}</span>
        </button>
      </div>
    </form>

    <!-- What this person has sent before, and what became of it. -->
    <section v-if="history.length > 0" class="feedback-history" aria-labelledby="feedback-history-title">
      <h4 id="feedback-history-title">Your Feedback</h4>
      <ul>
        <li v-for="item in history" :key="item.id" class="feedback-history-item">
          <div class="feedback-history-head">
            <span class="feedback-history-type">
              <component :is="feedbackType(item.type).icon" :size="14" aria-hidden="true" />
              {{ feedbackType(item.type).short }}
            </span>
            <span class="pill" :class="feedbackStatusTone(item.status)">{{ feedbackStatusLabel(item) }}</span>
          </div>
          <p class="feedback-history-message">{{ item.message }}</p>
          <p class="feedback-history-date">Sent {{ formatDateTime(item.created_at) }}</p>
          <div v-if="item.admin_reply" class="feedback-history-reply">
            <strong>Reply from the administrator</strong>
            <p>{{ item.admin_reply }}</p>
          </div>
        </li>
      </ul>
    </section>
  </article>
</template>

<script setup>
import InfoTip from '@/components/InfoTip.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { AlertCircle, CheckCircle2, Info, RefreshCw, Send } from 'lucide-vue-next'
import { feedbackApi } from '@/api/feedback.api'
import { useAuthStore } from '@/store/auth.store'
import { useLiveRefresh } from '@/utils/liveRefresh'
import {
  feedbackStatusLabel,
  feedbackStatusTone,
  feedbackType,
  feedbackTypes,
  satisfactionLabels,
} from '@/utils/feedbackLabels'
import { formatDateTime } from '../composables/useDashboardFormatters'

const auth = useAuthStore()

const form = reactive({ type: 'problem', rating: null, message: '', may_contact: false })
const errors = ref({})
const error = ref('')
const message = ref('')
const sending = ref(false)
const history = ref([])

// Settings is where the form lives, so "where were you" is the page before it.
const cameFrom = (() => {
  const back = typeof window !== 'undefined' ? window.history.state?.back : null

  return typeof back === 'string' ? back.split('?')[0] : null
})()

const placeholders = {
  problem: 'What happened, and what did you expect to happen? Which page, and what were you doing?',
  idea: 'What would you like BLIS to do, and how would it help you?',
  question: 'What would you like to know?',
  other: 'Tell us what is on your mind.',
}

const placeholder = computed(() => placeholders[form.type] ?? placeholders.other)
const tooShort = computed(() => form.message.trim().length > 0 && form.message.trim().length < 10)

// A second press on the chosen number clears it: the rating is optional.
function toggleRating(value) {
  form.rating = form.rating === value ? null : value
}

function appVersion() {
  const script = document.querySelector('script[type="module"][src*="/assets/"]')

  return script ? script.getAttribute('src') : 'development'
}

async function loadHistory() {
  try {
    const { data } = await feedbackApi.mine()
    history.value = data?.feedback ?? []
  } catch {
    // Leave the list as it was; the form still works.
  }
}

async function sendFeedback() {
  errors.value = {}
  error.value = ''
  message.value = ''

  if (form.message.trim().length < 10) {
    errors.value = { message: form.message.trim() ? 'Tell us a little more: at least 10 characters.' : 'Write your feedback first.' }
    return
  }

  sending.value = true

  try {
    const { data } = await feedbackApi.send({
      type: form.type,
      rating: form.rating,
      message: form.message.trim(),
      may_contact: form.may_contact,
      context: {
        page: cameFrom,
        app_version: appVersion(),
        screen: `${window.innerWidth}x${window.innerHeight}`,
      },
    })

    message.value = data?.message ?? 'Thank you for your feedback.'
    form.rating = null
    form.message = ''
    form.may_contact = false
    if (data?.feedback) history.value = [data.feedback, ...history.value]
  } catch (requestError) {
    const status = requestError?.response?.status
    errors.value = Object.fromEntries(
      Object.entries(requestError?.response?.data?.errors ?? {}).map(([field, messages]) => [field, String(messages?.[0] ?? '')]),
    )
    error.value = status === 429
      ? 'You have sent a lot of feedback in the last hour. Try again later.'
      : (Object.keys(errors.value).length > 0 ? '' : requestError?.response?.data?.message ?? 'Unable to send your feedback.')
  } finally {
    sending.value = false
  }
}

onMounted(loadHistory)

// The administrator's reply arrives while the page is open.
useLiveRefresh(loadHistory, { when: () => !sending.value })
</script>

<style scoped src="../dashboard.css"></style>

<style scoped src="./accountSettings.css"></style>

<style scoped>
.feedback-fieldset {
  display: grid;
  gap: 8px;
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}

.feedback-fieldset legend {
  padding: 0;
  margin-bottom: 8px;
}

.feedback-types {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.feedback-type {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  min-width: 0;
  padding: 12px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  background: #ffffff;
  color: var(--lnu-navy-deep);
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
}

.feedback-type:hover {
  border-color: rgba(26, 35, 126, 0.35);
}

.feedback-type.selected {
  border-color: var(--lnu-navy);
  background: rgba(26, 35, 126, 0.05);
  box-shadow: 0 0 0 1px var(--lnu-navy);
}

.feedback-type:focus-within {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

.feedback-type input,
.feedback-scale-option input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.feedback-type > svg {
  flex: none;
  margin-top: 1px;
}

.feedback-type-copy {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.feedback-type-copy strong {
  font-size: 13.5px;
}

.feedback-type-copy small {
  font-size: 12px;
  line-height: 1.35;
  color: var(--ink-soft);
}

.feedback-scale {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 6px;
  max-width: 420px;
}

.feedback-scale-option {
  position: relative;
  display: grid;
  place-items: center;
  height: var(--control-height, 40px);
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  background: #ffffff;
  color: var(--lnu-navy-deep);
  font-weight: 700;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}

.feedback-scale-option:hover {
  border-color: rgba(26, 35, 126, 0.35);
}

.feedback-scale-option.selected {
  border-color: var(--lnu-navy);
  background: var(--lnu-navy);
  color: #ffffff;
}

.feedback-scale-option:focus-within {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

.feedback-scale-ends {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  max-width: 420px;
  font-size: 11.5px;
  color: var(--ink-soft);
}

.feedback-scale-chosen {
  font-weight: 700;
  color: var(--lnu-navy-deep);
}

.feedback-count {
  justify-self: end;
  font-size: 12px;
  color: var(--ink-soft);
}

.feedback-count.short {
  color: var(--lnu-danger);
}

.feedback-contact {
  justify-self: start;
}

.feedback-contact span {
  overflow-wrap: anywhere;
}

.feedback-route {
  display: flex;
  gap: 10px;
  padding: 12px 14px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  background: var(--surface-muted);
  color: var(--lnu-navy-deep);
}

.feedback-route > svg {
  flex: none;
  margin-top: 2px;
}

.feedback-route p {
  margin: 0;
  font-size: 13px;
  line-height: 1.5;
}

.feedback-route .feedback-route-small {
  font-size: 12.5px;
  color: var(--ink-soft);
}

.feedback-history {
  display: grid;
  gap: 10px;
  padding-top: 16px;
  border-top: 1px solid var(--border-soft);
}

.feedback-history h4 {
  margin: 0;
  color: var(--lnu-navy-deep);
}

.feedback-history ul {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  margin: 0;
  padding: 0;
  list-style: none;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
}

.feedback-history-item {
  display: grid;
  gap: 6px;
  padding: 12px 14px;
}

.feedback-history-item + .feedback-history-item {
  border-top: 1px solid var(--border-soft);
}

.feedback-history-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.feedback-history-type {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--lnu-navy-deep);
}

.feedback-history-message {
  margin: 0;
  font-size: 13.5px;
  line-height: 1.5;
  color: var(--ink);
  white-space: pre-line;
  overflow-wrap: anywhere;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.feedback-history-date {
  margin: 0;
  font-size: 12px;
  color: var(--ink-soft);
}

.feedback-history-reply {
  padding: 10px 12px;
  border-left: 3px solid var(--lnu-navy);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  background: rgba(26, 35, 126, 0.05);
}

.feedback-history-reply strong {
  font-size: 12.5px;
  color: var(--lnu-navy-deep);
}

.feedback-history-reply p {
  margin: 4px 0 0;
  font-size: 13px;
  line-height: 1.5;
  white-space: pre-line;
  overflow-wrap: anywhere;
}

@media (min-width: 900px) {
  .feedback-types {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
</style>
