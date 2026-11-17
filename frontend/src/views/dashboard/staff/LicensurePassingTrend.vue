<template>
  <article ref="cardRef" class="surface-card room-shell-card is-flush lle-trend">
    <header class="room-page-head is-inline">
      <div class="room-page-title">
        <TrendingUp :size="18" />
        <h3>
          Passing Rate Trend
          <InfoTip :text="ABOUT" label="How the passing rates are counted" />
        </h3>
      </div>
      <div class="lle-trend-actions">
        <button v-if="canEnterNational" type="button" class="ghost-btn" @click="openForm('national')">
          <Plus :size="14" />
          National Figures
        </button>
        <button type="button" class="ghost-btn" @click="openForm('school')">
          <Plus :size="14" />
          Earlier LNU Figures
        </button>
      </div>
    </header>

    <div v-if="loading && !loaded" class="room-empty-state">
      <RefreshCw :size="18" class="spin-soft" />
      <p>Loading the passing rates…</p>
    </div>

    <div v-else-if="loadError" class="feedback danger lle-trend-feedback" role="alert">
      <AlertCircle :size="15" />
      <span>{{ loadError }}</span>
    </div>

    <div v-else-if="years.length === 0" class="room-empty-state">
      <TrendingUp :size="18" />
      <p>No passing rates yet. LNU's appear as results are recorded above; earlier years and the national rate can be entered with the buttons.</p>
    </div>

    <template v-else>
      <div class="lle-trend-chart">
        <!-- Both series keyed by the stroke the chart draws them with; the
             names stay in ink, never in the line's colour. -->
        <ul class="lle-trend-legend">
          <li><span class="lle-trend-key is-lnu" aria-hidden="true"></span>LNU</li>
          <li><span class="lle-trend-key is-national" aria-hidden="true"></span>National</li>
        </ul>
        <JsChart
          type="line"
          :labels="series.labels"
          :datasets="datasets"
          :max="100"
          unit="%"
          :format-value="formatRate"
          :height="isPhone ? 220 : 260"
          aria-label="LNU's and the national LLE passing rate by exam year, in percent. The list under the chart gives the same figures."
        />
      </div>

      <p v-if="!hasNational" class="muted lle-trend-note">
        {{ canEnterNational
          ? "No national figures yet: add them from the PRC's announcements with National Figures."
          : "No national figures yet: an administrator enters them from the PRC's announcements." }}
      </p>

      <!-- The figures behind the chart, newest year first. -->
      <ul v-if="useRows" class="directory-row-list is-in-card">
        <li v-for="row in newestFirst" :key="row.exam_year">
          <details class="directory-row-details">
            <summary class="directory-row">
              <span class="directory-row-copy">
                <strong>{{ row.exam_year }}</strong>
                <span>LNU {{ formatRate(row.lnu?.passing_rate) }} &middot; National {{ formatRate(row.national?.passing_rate) }}</span>
              </span>
              <ChevronDown :size="18" class="directory-row-more directory-row-chevron" aria-hidden="true" />
            </summary>

            <dl class="directory-row-facts">
              <div>
                <dt>LNU</dt>
                <dd>
                  {{ formatRate(row.lnu?.passing_rate) }}
                  <small v-for="line in lnuLines(row)" :key="line" class="muted lle-trend-sub">{{ line }}</small>
                </dd>
              </div>
              <div>
                <dt>National</dt>
                <dd>
                  {{ formatRate(row.national?.passing_rate) }}
                  <small v-for="line in nationalLines(row)" :key="line" class="muted lle-trend-sub">{{ line }}</small>
                </dd>
              </div>
              <div class="is-wide">
                <dt>LNU against national</dt>
                <dd>{{ formatDifference(row.difference) }}</dd>
              </div>
            </dl>

            <div v-if="canChange(row)" class="directory-row-actions">
              <template v-if="row.lnu?.from === 'entered'">
                <button type="button" class="ghost-btn" :disabled="busy" @click="openForm('school', row.lnu)">
                  <Pencil :size="15" />
                  Edit LNU
                </button>
                <button type="button" class="ghost-btn" :disabled="busy" @click="remove(row.lnu)">
                  <Trash2 :size="15" />
                  Remove LNU
                </button>
              </template>
              <template v-if="canEnterNational && row.national">
                <button type="button" class="ghost-btn" :disabled="busy" @click="openForm('national', row.national)">
                  <Pencil :size="15" />
                  Edit National
                </button>
                <button type="button" class="ghost-btn" :disabled="busy" @click="remove(row.national)">
                  <Trash2 :size="15" />
                  Remove National
                </button>
              </template>
            </div>
          </details>
        </li>
      </ul>

      <div v-else class="directory-table-wrap lle-trend-table-wrap">
        <table class="directory-table lle-trend-table">
          <caption class="visually-hidden">LNU's and the national LLE passing rate by exam year, newest first</caption>
          <thead>
            <tr>
              <th scope="col">Exam year</th>
              <th scope="col" class="numeric">LNU</th>
              <th scope="col" class="numeric">National</th>
              <th scope="col" class="numeric">LNU against national</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in newestFirst" :key="row.exam_year">
              <th scope="row">{{ row.exam_year }}</th>
              <td class="numeric">
                <strong v-if="row.lnu">{{ formatRate(row.lnu.passing_rate) }}</strong>
                <span v-else class="muted">—</span>
                <small v-for="line in lnuLines(row)" :key="line" class="muted lle-trend-sub">{{ line }}</small>
                <span v-if="row.lnu?.from === 'entered'" class="lle-trend-cell-actions">
                  <button type="button" class="lle-trend-link" :disabled="busy" @click="openForm('school', row.lnu)">Edit</button>
                  <button type="button" class="lle-trend-link" :disabled="busy" @click="remove(row.lnu)">Remove</button>
                </span>
              </td>
              <td class="numeric">
                <strong v-if="row.national">{{ formatRate(row.national.passing_rate) }}</strong>
                <span v-else class="muted">—</span>
                <small v-for="line in nationalLines(row)" :key="line" class="muted lle-trend-sub">{{ line }}</small>
                <span v-if="canEnterNational && row.national" class="lle-trend-cell-actions">
                  <button type="button" class="lle-trend-link" :disabled="busy" @click="openForm('national', row.national)">Edit</button>
                  <button type="button" class="lle-trend-link" :disabled="busy" @click="remove(row.national)">Remove</button>
                </span>
              </td>
              <td class="numeric" :class="{ muted: row.difference === null }">{{ formatDifference(row.difference) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <div v-if="actionError" class="feedback danger lle-trend-feedback" role="alert">
      <AlertCircle :size="15" />
      <span>{{ actionError }}</span>
    </div>
    <div v-else-if="notice" class="feedback success lle-trend-feedback" role="status">
      <CheckCircle2 :size="15" />
      <span>{{ notice }}</span>
    </div>

    <LicensurePassingRateForm
      :open="formOpen"
      :scope="formScope"
      :editing="formEditing"
      :years="years"
      @close="formOpen = false"
      @saved="onSaved"
    />
  </article>
</template>

<script setup>
import InfoTip from '@/components/InfoTip.vue'
import JsChart from '@/components/charts/JsChart.vue'
import { computed, onMounted, ref } from 'vue'
import { AlertCircle, CheckCircle2, ChevronDown, Pencil, Plus, RefreshCw, Trash2, TrendingUp } from 'lucide-vue-next'
import { useAuthStore } from '@/store/auth.store'
import { useLiveRefresh } from '@/utils/liveRefresh'
import { useDashboardDataServices } from '../composables/useDashboardDataServices'
import { estimatePageWidth, useElementWidth, usePhoneLayout } from '../composables/useViewport'
import LicensurePassingRateForm from './LicensurePassingRateForm.vue'
import { formatCounts, formatDifference, formatRate, trendSeries } from './licensureTrend'

/*
 * The Licensure page's passing-rate trend: LNU's rate for each exam year
 * beside the national one, as a chart and the figures behind it. The rates
 * come from the server (LlePassingRateTrend); this card only draws them and
 * opens the form for the figures typed in.
 */

const ABOUT = "LNU's rate for an exam year is how many passed out of everyone who took it that year, repeaters included, as the PRC counts the national rate; Did Not Take is left out. It is counted from the results recorded on this page. For a year before BLIS, LNU's figures can be entered by hand, and figures entered for a year are shown in place of its recorded results. An administrator enters the national figures from the PRC's announcements."

// The series' colours, checked as a pair for colour-blind readers in both
// themes; National is also dashed, so colour is never the only difference.
const LNU_COLOR = '#3949ab'
const NATIONAL_COLOR = '#d55181'

// Four columns with two-line cells need about 640px.
const TABLE_MIN_WIDTH = 700

const auth = useAuthStore()
const services = useDashboardDataServices()
const isPhone = usePhoneLayout()

const cardRef = ref(null)
const cardWidth = useElementWidth(cardRef, estimatePageWidth())
const useRows = computed(() => (cardWidth.value > 0 ? cardWidth.value < TABLE_MIN_WIDTH : isPhone.value))

const years = ref([])
const loading = ref(false)
const loaded = ref(false)
const loadError = ref('')
const busy = ref(false)
const actionError = ref('')
const notice = ref('')

const formOpen = ref(false)
const formScope = ref('school')
const formEditing = ref(null)

// National figures are an administrator's to enter (LlePassingRateController).
const canEnterNational = computed(() => String(auth.user?.role ?? '').toLowerCase() === 'admin')

const series = computed(() => trendSeries(years.value))
const datasets = computed(() => [
  { label: 'LNU', data: series.value.lnu, colors: LNU_COLOR, details: series.value.lnuDetails },
  { label: 'National', data: series.value.national, colors: NATIONAL_COLOR, dashed: true, details: series.value.nationalDetails },
])
const newestFirst = computed(() => [...years.value].sort((a, b) => Number(b.exam_year) - Number(a.exam_year)))
const hasNational = computed(() => years.value.some((row) => row.national))

function lnuLines(row) {
  if (!row.lnu) return []

  const lines = []
  if (formatCounts(row.lnu)) lines.push(formatCounts(row.lnu))

  if (row.lnu.from === 'entered') {
    lines.push(row.lnu.source ? `Entered: ${row.lnu.source}` : 'Entered by hand')
    // What BLIS itself holds for that year, which the entered figures replace.
    if (row.recorded) lines.push(`Recorded here: ${formatCounts(row.recorded)}`)
  } else {
    lines.push('From the results recorded here')
  }

  return lines
}

function nationalLines(row) {
  if (!row.national) return []

  return [formatCounts(row.national), row.national.source ?? ''].filter(Boolean)
}

const canChange = (row) => row.lnu?.from === 'entered' || (canEnterNational.value && Boolean(row.national))

async function load({ silent = false } = {}) {
  if (!silent) {
    loading.value = true
    loadError.value = ''
  }

  try {
    const { data } = await services.getLlePassingRates()
    years.value = Array.isArray(data?.years) ? data.years : []
    loaded.value = true
    loadError.value = ''
  } catch (error) {
    if (!silent) loadError.value = error?.response?.data?.message ?? 'Unable to load the passing rates.'
  } finally {
    if (!silent) loading.value = false
  }
}

function openForm(scope, figure = null) {
  formScope.value = scope
  formEditing.value = figure
  actionError.value = ''
  notice.value = ''
  formOpen.value = true
}

function onSaved(data) {
  formOpen.value = false
  if (Array.isArray(data?.years)) years.value = data.years
  notice.value = data?.message ?? ''
}

// Like a recorded result, a figure entered by mistake is removed in one
// press; it is in the audit log, and quick to enter again.
async function remove(figure) {
  if (!figure?.id || busy.value) return

  busy.value = true
  actionError.value = ''
  notice.value = ''

  try {
    const { data } = await services.removeLlePassingRate(figure.id)
    if (Array.isArray(data?.years)) years.value = data.years
    notice.value = data?.message ?? ''
  } catch (error) {
    actionError.value = error?.response?.data?.message ?? 'Unable to remove those figures.'
  } finally {
    busy.value = false
  }
}

onMounted(() => load())

useLiveRefresh(() => load({ silent: true }), {
  when: () => !loading.value && !busy.value && !formOpen.value,
})

// The page reloads it after recording or removing a result.
defineExpose({ reload: () => load({ silent: true }) })
</script>

<style scoped>
.lle-trend-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.lle-trend-chart {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 8px;
  margin: 0 18px 14px;
}

.lle-trend-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 18px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink);
}

.lle-trend-legend li {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

/* The chart's own strokes: 2px, LNU solid, National dashed. */
.lle-trend-key {
  width: 24px;
  height: 0;
  border-top: 2px solid var(--dk-line-3949ab, #3949ab);
}

.lle-trend-key.is-national {
  border-top: 2px dashed var(--dk-line-d55181, #d55181);
}

.lle-trend-note {
  margin: 0 18px 14px;
  font-size: 12.5px;
  line-height: 1.5;
}

.lle-trend-table-wrap {
  margin: 0 18px 14px;
}

/* Sized by its four columns, like the other tables on this page. */
.lle-trend-table {
  min-width: 0;
}

.lle-trend-table thead th {
  padding: 9px 12px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.lle-trend-table th.numeric {
  text-align: right;
}

.lle-trend-table tbody th {
  font-weight: 700;
  text-align: left;
  vertical-align: top;
}

.lle-trend-table td {
  vertical-align: top;
  font-variant-numeric: tabular-nums;
}

.lle-trend-sub {
  display: block;
  font-size: 12px;
  font-weight: 400;
  line-height: 1.4;
  overflow-wrap: anywhere;
}

.lle-trend-cell-actions {
  display: inline-flex;
  gap: 12px;
  margin-top: 4px;
}

.lle-trend-link {
  padding: 0;
  border: 0;
  background: transparent;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
  text-decoration: underline;
  text-underline-offset: 2px;
  cursor: pointer;
}

.lle-trend-link:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.lle-trend-feedback {
  margin: 0 18px 14px;
}

@media (max-width: 640px) {
  .lle-trend-chart,
  .lle-trend-note,
  .lle-trend-feedback {
    margin-inline: 14px;
  }

  /* The title on its own line, the buttons side by side under it. */
  .lle-trend > .room-page-head {
    flex-wrap: wrap;
    row-gap: 10px;
  }

  .lle-trend-actions {
    flex: 1 1 100%;
  }

  .lle-trend-actions .ghost-btn {
    flex: 1 1 0;
    justify-content: center;
    padding-inline: 8px;
  }
}
</style>
