<template>
  <article class="surface-card account-card">
    <header class="account-card-head">
      <h3>New Exam Defaults</h3>
      <p>What the New Exam form starts with. You can still change any of it for a single exam.</p>
    </header>

    <form class="account-form" novalidate @submit.prevent="save">
      <div class="account-grid two">
        <label class="field-stack">
          <span class="field-label">Number of Items</span>
          <input v-model.number="form.total_items" type="number" inputmode="numeric" min="1" max="1000" class="text-input" />
          <span v-if="errors.total_items" class="account-field-error" role="alert">{{ errors.total_items }}</span>
        </label>

        <label class="field-stack">
          <span class="field-label">Time Limit (minutes)</span>
          <input v-model.number="form.duration_minutes" type="number" inputmode="numeric" min="1" max="600" class="text-input" />
          <span v-if="errors.duration_minutes" class="account-field-error" role="alert">{{ errors.duration_minutes }}</span>
        </label>
      </div>

      <div class="account-checks">
        <label class="check-item">
          <input v-model="form.one_take_only" type="checkbox" />
          <span>One take only</span>
        </label>

        <label class="check-item">
          <input v-model="form.shuffle_questions" type="checkbox" />
          <span>Shuffle questions</span>
        </label>
      </div>

      <fieldset class="account-radio-group">
        <legend>Results Visibility</legend>
        <label class="check-item">
          <input v-model="form.results_visibility_mode" type="radio" value="hidden" />
          <span>Hidden: students see only their final score</span>
        </label>
        <label class="check-item">
          <input v-model="form.results_visibility_mode" type="radio" value="visible" />
          <span>Visible: students can also review right and wrong answers</span>
        </label>
      </fieldset>

      <div v-if="message" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ message }}</span></div>
      <div v-if="error" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ error }}</span></div>

      <div class="account-actions split">
        <button type="button" class="ghost-btn" :disabled="saving || isStandard" @click="useStandard">Use the Standard Settings</button>
        <button type="submit" class="primary-btn" :disabled="saving || !changed">
          <RefreshCw v-if="saving" :size="14" class="spin-soft" />
          <span>{{ saving ? 'Saving...' : 'Save Defaults' }}</span>
        </button>
      </div>
    </form>
  </article>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { AlertCircle, CheckCircle2, RefreshCw } from 'lucide-vue-next'
import { accountApi } from '@/api/account.api'
import { useAuthStore } from '@/store/auth.store'
import { STANDARD_EXAM_DEFAULTS, examDefaultsFor } from '@/utils/examDefaults'

const auth = useAuthStore()

const form = reactive({ ...STANDARD_EXAM_DEFAULTS })
const errors = ref({})
const error = ref('')
const message = ref('')
const saving = ref(false)

const saved = computed(() => examDefaultsFor(auth.user))

watch(() => auth.user?.id, () => Object.assign(form, saved.value), { immediate: true })

const same = (a, b) => Object.keys(STANDARD_EXAM_DEFAULTS).every((key) => a[key] === b[key])
const changed = computed(() => !same(form, saved.value))
const isStandard = computed(() => same(form, STANDARD_EXAM_DEFAULTS))

function useStandard() {
  Object.assign(form, STANDARD_EXAM_DEFAULTS)
  message.value = ''
}

async function save() {
  errors.value = {}
  error.value = ''
  message.value = ''

  const problems = {}
  const whole = (value) => Number.isInteger(value)

  if (!whole(form.total_items) || form.total_items < 1 || form.total_items > 1000) {
    problems.total_items = 'Enter a whole number from 1 to 1000.'
  }

  if (!whole(form.duration_minutes) || form.duration_minutes < 1 || form.duration_minutes > 600) {
    problems.duration_minutes = 'Enter a whole number of minutes from 1 to 600.'
  }

  if (Object.keys(problems).length > 0) {
    errors.value = problems
    return
  }

  saving.value = true

  try {
    const { data } = await accountApi.savePreferences({ exam_defaults: { ...form } })

    auth.user = { ...auth.user, ...data.user }
    Object.assign(form, saved.value)
    message.value = 'Saved. Your next new exam starts with these.'
  } catch (failure) {
    const sent = failure?.response?.data?.errors ?? {}

    errors.value = Object.fromEntries(
      Object.entries(sent).map(([field, messages]) => [field.replace('exam_defaults.', ''), String(messages?.[0] ?? '')]),
    )
    error.value = Object.keys(sent).length > 0 ? '' : (failure?.response?.data?.message ?? 'Unable to save your defaults.')
  } finally {
    saving.value = false
  }
}
</script>

<style scoped src="../dashboard.css"></style>
<style scoped src="./accountSettings.css"></style>
