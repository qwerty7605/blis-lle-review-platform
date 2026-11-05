<template>
  <article class="surface-card account-card">
    <header class="account-card-head">
      <h3>New Exam Defaults <InfoTip text="What the New Exam form starts with. You can still change any of it for a single exam." /></h3>
    </header>

    <form class="account-form" novalidate @submit.prevent="save">
      <div class="account-grid two">
        <label class="field-stack">
          <span class="field-label">Number of Items</span>
          <input v-model.number="form.total_items" type="number" inputmode="numeric" min="1" max="1000" class="text-input" v-bind="checks.bind('total_items')" />
          <FieldError :checks="checks" field="total_items" />
        </label>

        <label class="field-stack">
          <span class="field-label">Time Limit (minutes)</span>
          <input v-model.number="form.duration_minutes" type="number" inputmode="numeric" min="1" max="600" class="text-input" v-bind="checks.bind('duration_minutes')" />
          <FieldError :checks="checks" field="duration_minutes" />
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

      <fieldset v-if="form.results_visibility_mode === 'visible' && !form.one_take_only" class="account-radio-group">
        <legend>Show Correct Answers</legend>
        <label class="check-item">
          <input v-model="form.answers_shown_after" type="radio" value="last_attempt" />
          <span>After the last attempt: once the retake is used or the exam closes (Review Bot practice opens then too)</span>
        </label>
        <label class="check-item">
          <input v-model="form.answers_shown_after" type="radio" value="each_attempt" />
          <span>After each attempt, even with the retake still to come</span>
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
import FieldError from '@/components/FieldError.vue'
import InfoTip from '@/components/InfoTip.vue'
import { computed, reactive, ref, watch } from 'vue'
import { AlertCircle, CheckCircle2, RefreshCw } from 'lucide-vue-next'
import { accountApi } from '@/api/account.api'
import { useAuthStore } from '@/store/auth.store'
import { STANDARD_EXAM_DEFAULTS, examDefaultsFor } from '@/utils/examDefaults'
import { useFieldChecks, wholeNumberProblem } from '@/utils/fieldChecks'

const auth = useAuthStore()

const form = reactive({ ...STANDARD_EXAM_DEFAULTS })
// The exam form's own limits (ExamController::store).
const checks = useFieldChecks('exam-defaults', {
  total_items: () => wholeNumberProblem(form.total_items, { label: 'Number of items', min: 1, max: 1000 }),
  duration_minutes: () => wholeNumberProblem(form.duration_minutes, { label: 'Time limit', min: 1, max: 600 }),
})
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
  checks.reset()
}

async function save() {
  error.value = ''
  message.value = ''

  if (!checks.check()) return

  saving.value = true

  try {
    const { data } = await accountApi.savePreferences({ exam_defaults: { ...form } })

    auth.user = { ...auth.user, ...data.user }
    Object.assign(form, saved.value)
    message.value = 'Saved. Your next new exam starts with these.'
    checks.reset()
  } catch (failure) {
    // The server names the fields exam_defaults.total_items and so on.
    const sent = failure?.response?.data?.errors ?? {}
    const placed = checks.takeServerErrors({
      response: {
        data: {
          errors: Object.fromEntries(Object.entries(sent).map(([field, messages]) => [field.replace('exam_defaults.', ''), messages])),
        },
      },
    })

    error.value = placed ? '' : (Object.values(sent).flat()[0] ?? failure?.response?.data?.message ?? 'Unable to save your defaults.')
  } finally {
    saving.value = false
  }
}
</script>

<style scoped src="./accountSettings.css"></style>
