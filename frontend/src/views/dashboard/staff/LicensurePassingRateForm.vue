<template>
  <!-- Figures typed in for one exam year of the passing-rate trend: the
       PRC's national ones (administrators), or LNU's for a year before BLIS
       kept its results (teachers and administrators). -->
  <Teleport to="body">
    <div v-if="open" class="modal-backdrop" @click.self="close">
      <div
        class="modal-card lle-rate-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="lle-rate-title"
      >
        <header class="modal-head">
          <h4 id="lle-rate-title">{{ title }}</h4>
          <button type="button" class="modal-close" aria-label="Close" :disabled="saving" @click="close">
            <X :size="16" />
          </button>
        </header>

        <p class="lle-rate-intro">{{ intro }}</p>

        <div class="lle-rate-grid">
          <label class="field-stack">
            <span class="field-label">Exam Year</span>
            <input
              ref="yearInput"
              v-model.trim="form.exam_year"
              type="number"
              inputmode="numeric"
              class="text-input"
              min="1900"
              max="2999"
              :disabled="Boolean(editing)"
              v-bind="checks.bind('exam_year')"
            />
            <FieldError :checks="checks" field="exam_year" />
          </label>

          <label class="field-stack">
            <span class="field-label">Passed <em>(optional)</em></span>
            <input v-model.trim="form.passers" type="number" inputmode="numeric" class="text-input" min="0" step="1" v-bind="checks.bind('passers')" />
            <FieldError :checks="checks" field="passers" />
          </label>

          <label class="field-stack">
            <span class="field-label">Took the Exam <em>(optional)</em></span>
            <input v-model.trim="form.takers" type="number" inputmode="numeric" class="text-input" min="1" step="1" v-bind="checks.bind('takers')" />
            <FieldError :checks="checks" field="takers" />
          </label>

          <label class="field-stack">
            <span class="field-label">
              Passing Rate (%)
              <InfoTip text="With both numbers filled in, the rate is worked out from them, as the PRC reports it. Without them, type the rate the PRC announced." label="About the passing rate" />
            </span>
            <input
              v-if="computedRate !== null"
              :value="computedRate.toFixed(2)"
              type="text"
              class="text-input"
              readonly
              aria-describedby="lle-rate-computed"
            />
            <input
              v-else
              v-model.trim="form.passing_rate"
              type="number"
              inputmode="decimal"
              class="text-input"
              min="0"
              max="100"
              step="0.01"
              v-bind="checks.bind('passing_rate')"
            />
            <small v-if="computedRate !== null" id="lle-rate-computed" class="muted lle-rate-hint">Worked out from the two numbers.</small>
            <FieldError :checks="checks" field="passing_rate" />
          </label>

          <label class="field-stack lle-rate-wide">
            <span class="field-label">Source <em>(optional)</em></span>
            <input
              v-model="form.source"
              type="text"
              class="text-input"
              maxlength="255"
              :placeholder="scope === 'national' ? 'e.g. PRC announcement, September 2025' : 'e.g. Registrar\'s records'"
              v-bind="checks.bind('source')"
            />
            <FieldError :checks="checks" field="source" />
          </label>
        </div>

        <!-- Said before saving: what this replaces, or hides from the trend. -->
        <ul v-if="notes.length > 0" class="lle-rate-notes" aria-live="polite">
          <li v-for="note in notes" :key="note">
            <Info :size="14" aria-hidden="true" />
            <span>{{ note }}</span>
          </li>
        </ul>

        <div v-if="saveError" class="feedback danger" role="alert">
          <AlertCircle :size="15" />
          <span>{{ saveError }}</span>
        </div>

        <div class="modal-actions">
          <button type="button" class="ghost-btn" :disabled="saving" @click="close">Cancel</button>
          <button type="button" class="primary-btn" :disabled="saving" @click="save">
            <RefreshCw v-if="saving" :size="14" class="spin-soft" />
            <Save v-else :size="14" />
            {{ saving ? 'Saving...' : 'Save Figures' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import FieldError from '@/components/FieldError.vue'
import InfoTip from '@/components/InfoTip.vue'
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue'
import { AlertCircle, Info, RefreshCw, Save, X } from 'lucide-vue-next'
import { useDashboardDataServices } from '../composables/useDashboardDataServices'
import { decimalProblem, lengthProblem, useFieldChecks, wholeNumberProblem, yearProblem } from '@/utils/fieldChecks'
import { countsProblem, examYearProblem, PASSING_RATE_MESSAGES, rateOf } from '@/utils/lleResultChecks'
import { formatCounts, formatRate } from './licensureTrend'

const props = defineProps({
  open: { type: Boolean, default: false },
  // 'national' or 'school'
  scope: { type: String, default: 'school' },
  // The figure being changed ({ id, exam_year, passers, takers, passing_rate, source }), or null for new ones.
  editing: { type: Object, default: null },
  // The trend's years, for what saving would replace or hide.
  years: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'saved'])
const services = useDashboardDataServices()

const MAX_COUNT = 1000000

const form = reactive({ exam_year: '', passers: '', takers: '', passing_rate: '', source: '' })
const saving = ref(false)
const saveError = ref('')
const yearInput = ref(null)

const text = (value) => String(value ?? '').trim()

const title = computed(() => {
  const whose = props.scope === 'national' ? 'National Figures' : 'LNU Figures'

  return props.editing ? `Edit ${whose} for ${props.editing.exam_year}` : (props.scope === 'national' ? 'National Figures' : 'LNU Figures for an Earlier Year')
})

const intro = computed(() => (props.scope === 'national'
  ? "The PRC's results for everyone who took that year's LLE, from its announcement: how many passed out of how many took it, or the passing rate alone."
  : "LNU's results for a year before they were recorded in BLIS: how many of its graduates passed out of how many took it, first takers and repeaters alike, as the PRC counts."))

// Both numbers make the rate: it is worked out, as the server does, so the
// three can never disagree.
const computedRate = computed(() => {
  const passers = text(form.passers)
  const takers = text(form.takers)
  if (passers === '' || takers === '') return null
  if (!Number.isInteger(Number(passers)) || !Number.isInteger(Number(takers)) || Number(takers) < 1 || Number(passers) > Number(takers)) return null

  return rateOf(passers, takers)
})

const yearRow = computed(() => props.years.find((row) => Number(row.exam_year) === Number(form.exam_year)) ?? null)

const notes = computed(() => {
  const row = yearRow.value
  if (!row || yearProblem(form.exam_year, { required: true })) return []

  const result = []
  const onRecord = props.scope === 'national' ? row.national : (row.lnu?.from === 'entered' ? row.lnu : null)

  if (onRecord && Number(onRecord.id) !== Number(props.editing?.id)) {
    result.push(`Figures for ${row.exam_year} are already entered: ${formatRate(onRecord.passing_rate)}${onRecord.takers ? `, ${formatCounts(onRecord)}` : ''}. Saving replaces them.`)
  }

  if (props.scope === 'school' && row.recorded) {
    result.push(`BLIS has ${row.recorded.takers} ${row.recorded.takers === 1 ? 'result' : 'results'} recorded for ${row.exam_year} (${formatCounts(row.recorded)}, ${formatRate(row.recorded.passing_rate)}). The trend shows the figures saved here for ${row.exam_year} instead.`)
  }

  return result
})

const checks = useFieldChecks('lle-rate', {
  exam_year: () => yearProblem(form.exam_year, { label: 'Exam year', required: true }) || examYearProblem(form.exam_year),
  passers: () => wholeNumberProblem(form.passers, { label: 'Number who passed', min: 0, max: MAX_COUNT, required: false })
    || (countsProblem(form.passers, form.takers)?.field === 'passers' ? countsProblem(form.passers, form.takers).message : ''),
  takers: () => wholeNumberProblem(form.takers, { label: 'Number who took it', min: 1, max: MAX_COUNT, required: false })
    || (countsProblem(form.passers, form.takers)?.field === 'takers' ? countsProblem(form.passers, form.takers).message : ''),
  passing_rate: () => {
    if (text(form.passers) !== '' || text(form.takers) !== '') return ''

    return decimalProblem(form.passing_rate, { label: 'Passing rate', min: 0, max: 100 })
      || (text(form.passing_rate) === '' ? PASSING_RATE_MESSAGES.rateNeeded : '')
  },
  source: () => lengthProblem(form.source, { label: 'Source', max: 255 }),
})

function fill() {
  const figure = props.editing
  form.exam_year = figure ? String(figure.exam_year) : ''
  form.passers = figure?.passers ?? ''
  form.takers = figure?.takers ?? ''
  form.passing_rate = figure && figure.takers === null ? String(figure.passing_rate) : ''
  form.source = figure?.source ?? ''
  saveError.value = ''
  checks.reset()
}

function close() {
  if (saving.value) return
  emit('close')
}

function onKeydown(event) {
  if (event.key === 'Escape') close()
}

watch(() => props.open, (open) => {
  if (open) {
    fill()
    document.addEventListener('keydown', onKeydown)
    nextTick(() => yearInput.value?.focus())
  } else {
    document.removeEventListener('keydown', onKeydown)
  }
}, { immediate: true })

onUnmounted(() => document.removeEventListener('keydown', onKeydown))

async function save() {
  if (saving.value || !checks.check()) return

  saving.value = true
  saveError.value = ''
  const counted = text(form.passers) !== '' && text(form.takers) !== ''

  try {
    const { data } = await services.recordLlePassingRate({
      scope: props.scope,
      exam_year: Number(form.exam_year),
      passers: counted ? Number(form.passers) : null,
      takers: counted ? Number(form.takers) : null,
      passing_rate: counted ? null : Number(form.passing_rate),
      source: text(form.source) || null,
    })

    emit('saved', data)
  } catch (error) {
    if (!checks.takeServerErrors(error)) {
      saveError.value = error?.response?.data?.message ?? 'Unable to save those figures.'
    }
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.lle-rate-card {
  max-width: 560px;
}

.lle-rate-intro {
  margin: 0 0 14px;
  font-size: 13px;
  line-height: 1.5;
  color: var(--ink-soft);
}

/* start: a hint or a problem under one field leaves its neighbour where it
   is, instead of stretching it and pushing its box down. */
.lle-rate-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  align-items: start;
  gap: 12px 14px;
}

.lle-rate-wide {
  grid-column: 1 / -1;
}

.field-label em {
  font-style: normal;
  font-weight: 400;
  color: var(--ink-soft);
}

.lle-rate-hint {
  font-size: 12px;
}

.lle-rate-notes {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 6px;
  margin: 14px 0 0;
  padding: 10px 12px;
  list-style: none;
  border-left: 3px solid var(--border-strong);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  background: var(--surface-muted);
}

.lle-rate-notes li {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 13px;
  line-height: 1.45;
  color: var(--ink);
}

.lle-rate-notes svg {
  flex: none;
  margin-top: 2px;
  color: var(--ink-soft);
}

@media (max-width: 480px) {
  .lle-rate-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
