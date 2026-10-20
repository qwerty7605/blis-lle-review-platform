<template>
  <section class="dashboard-view profile-page">
    <RouterLink :to="backTarget.to" class="profile-back">
      <ArrowLeft :size="15" />
      {{ backTarget.label }}
    </RouterLink>

    <div v-if="loading && !profile" class="profile-state">
      <RefreshCw :size="26" class="spin-soft" />
      <p>Loading this student's profile...</p>
    </div>

    <div v-else-if="errorMessage" class="profile-state" role="alert">
      <AlertCircle :size="26" />
      <strong>{{ errorMessage }}</strong>
      <button type="button" class="primary-btn" @click="loadProfile">Try again</button>
    </div>

    <template v-else-if="profile">
      <!-- Who this is -->
      <header class="profile-hero">
        <span class="profile-avatar" aria-hidden="true">{{ initials }}</span>

        <div class="profile-hero-copy">
          <h2 class="profile-name">{{ student.name }}</h2>
          <p class="profile-contact">
            <span v-if="student.student_id">ID {{ student.student_id }}</span>
            <span>{{ student.email }}</span>
          </p>

          <ul class="profile-chips">
            <li><GraduationCap :size="13" />{{ yearLevelLabel }}</li>
            <li><CircleDot :size="13" />{{ statusLabel }}</li>
            <li v-if="student.admission_year"><CalendarDays :size="13" />Batch {{ student.admission_year }}</li>
            <li v-if="student.archived_at" class="is-warning"><Archive :size="13" />Archived</li>
          </ul>
        </div>

        <button
          type="button"
          class="profile-refresh"
          :disabled="loading"
          title="Refresh"
          aria-label="Refresh profile"
          @click="loadProfile"
        >
          <RefreshCw :size="15" :class="{ 'spin-soft': loading }" />
        </button>
      </header>

      <div v-if="stats.completed_attempts === 0" class="profile-state">
        <ClipboardList :size="26" />
        <strong>No finished exams yet</strong>
        <p>Scores, subjects and weak topics appear here once this student submits an exam.</p>
      </div>

      <template v-else>
        <!-- Where they stand -->
        <div class="profile-top-grid">
          <article class="profile-card" aria-labelledby="profile-outcome-heading">
            <header class="profile-card-head">
              <h3 id="profile-outcome-heading"><Gauge :size="17" />Estimated Outcome</h3>
              <p>From the latest complete semester</p>
            </header>

            <EstimatedOutcome
              class="profile-outcome"
              :readiness="readiness"
              :average-score-display="formatScore(stats.average_score_percent)"
              :focus-subjects-count="stats.focus_subjects_count"
            />
          </article>

          <article class="profile-card" aria-labelledby="profile-trend-heading">
            <header class="profile-card-head">
              <h3 id="profile-trend-heading"><TrendingUp :size="17" />Score Trend</h3>
              <p>The last {{ trendPoints.length }} finished {{ trendPoints.length === 1 ? 'exam' : 'exams' }}, oldest first</p>
            </header>

            <div class="profile-trend-stats">
              <div class="profile-stat">
                <span>Average</span>
                <strong>{{ formatScore(stats.average_score_percent) }}</strong>
              </div>
              <div class="profile-stat">
                <span>Latest</span>
                <strong>{{ formatScore(stats.latest_score_percent) }}</strong>
              </div>
              <div class="profile-stat">
                <span>Exams taken</span>
                <strong>{{ stats.completed_attempts }}</strong>
              </div>
            </div>

            <div class="profile-trend-chart">
              <ScoreTrendChart :points="trendPoints" :threshold="stats.passing_threshold" variant="compact" />
            </div>
          </article>
        </div>

        <!-- By subject -->
        <article class="profile-card" aria-labelledby="profile-subjects-heading">
          <header class="profile-card-head">
            <h3 id="profile-subjects-heading">
              <LayoutGrid :size="17" />Subjects
              <InfoTip :text="`Average score across every finished exam in each subject. The pass mark is ${stats.passing_threshold}%.`" />
            </h3>
          </header>

          <ul class="profile-subjects">
            <li v-for="subject in subjects" :key="subject.label" class="profile-subject">
              <div class="profile-subject-top">
                <strong>{{ subject.label }}</strong>
                <span class="profile-subject-score" :class="`is-${scoreTone(subject.average_score_percent)}`">
                  {{ formatScore(subject.average_score_percent) }}
                </span>
              </div>

              <div
                class="profile-bar"
                role="img"
                :aria-label="`${subject.label}: ${formatScore(subject.average_score_percent)} average`"
              >
                <span
                  class="profile-bar-fill"
                  :class="`is-${scoreTone(subject.average_score_percent)}`"
                  :style="{ width: `${clampPercent(subject.average_score_percent)}%` }"
                />
              </div>

              <p class="profile-subject-meta">
                <template v-if="subject.attempts_count === 0">No finished exam yet</template>
                <template v-else>
                  {{ subject.attempts_count }} {{ subject.attempts_count === 1 ? 'exam' : 'exams' }}
                  · latest {{ formatScore(subject.latest_score_percent) }}
                  · best {{ formatScore(subject.best_score_percent) }}
                </template>
              </p>
            </li>
          </ul>
        </article>

        <div class="profile-bottom-grid">
          <!-- Weakest topics -->
          <article class="profile-card" aria-labelledby="profile-weak-heading">
            <header class="profile-card-head">
              <h3 id="profile-weak-heading"><Target :size="17" />Weakest Topics</h3>
              <p>Ranked worst first, from how this student answered each mapped question.</p>
            </header>

            <p v-if="studyPlan.length === 0" class="profile-empty">
              Nothing flagged. Every topic this student has answered is at or above the pass mark.
            </p>

            <ol v-else class="profile-list">
              <li v-for="(item, index) in studyPlan" :key="`${item.level}-${item.subtopic_id ?? item.topic_id}-${index}`" class="profile-row">
                <span class="profile-rank" aria-hidden="true">{{ index + 1 }}</span>
                <div class="profile-row-copy">
                  <strong>{{ item.label }}</strong>
                  <span>{{ planContext(item) }}</span>
                </div>
                <span class="profile-row-score" :class="`is-${scoreTone(item.score)}`">{{ formatWholeScore(item.score) }}</span>
              </li>
            </ol>
          </article>

          <!-- Recent exams -->
          <article class="profile-card" aria-labelledby="profile-recent-heading">
            <header class="profile-card-head">
              <h3 id="profile-recent-heading"><ClipboardList :size="17" />Recent Exams</h3>
              <p>The most recent submissions, newest first.</p>
            </header>

            <ul class="profile-list">
              <li v-for="attempt in recentAttempts" :key="attempt.attempt_id" class="profile-row">
                <div class="profile-row-copy">
                  <strong>{{ attempt.title }}</strong>
                  <span>{{ attemptContext(attempt) }}</span>
                </div>
                <span class="profile-row-score" :class="`is-${scoreTone(attempt.score_percent)}`">
                  {{ formatScore(attempt.score_percent) }}
                </span>
              </li>
            </ul>
          </article>
        </div>
      </template>
    </template>
  </section>
</template>

<script setup>
import InfoTip from '@/components/InfoTip.vue'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  AlertCircle,
  Archive,
  ArrowLeft,
  CalendarDays,
  CircleDot,
  ClipboardList,
  Gauge,
  GraduationCap,
  LayoutGrid,
  RefreshCw,
  Target,
  TrendingUp,
} from 'lucide-vue-next'
import { studentsApi } from '@/api/students.api'
import EstimatedOutcome from '@/components/EstimatedOutcome.vue'
import { useLiveRefresh } from '@/utils/liveRefresh'
import ScoreTrendChart from '@/components/charts/ScoreTrendChart.vue'
import { describeReadiness } from '../composables/readiness'
import { formatCompactDate } from '../composables/useDashboardFormatters'

/*
 * One student as a teacher or an administrator sees them: who they are, then
 * the same analytics the student has on their own Analytics page, read-only.
 * Reached by clicking a name in the Students directory.
 */
const route = useRoute()

// Opened from a room's Class List, the way back is that room; otherwise it
// is the Students directory.
const backTarget = computed(() => {
  const roomId = Number(route.query.room)

  return Number.isFinite(roomId) && roomId > 0
    ? { label: 'Back to Room', to: { name: 'dashboard-room-management', query: { room: roomId } } }
    : { label: 'Back to Students', to: { name: 'dashboard-students' } }
})

const profile = ref(null)
const loading = ref(false)
const errorMessage = ref('')

const student = computed(() => profile.value?.student ?? {})
const stats = computed(() => ({
  completed_attempts: 0,
  average_score_percent: null,
  latest_score_percent: null,
  focus_subjects_count: 0,
  passing_threshold: 75,
  ...(profile.value?.stats ?? {}),
}))
const subjects = computed(() => profile.value?.subjects ?? [])
const studyPlan = computed(() => profile.value?.study_plan ?? [])
const recentAttempts = computed(() => profile.value?.recent_attempts ?? [])

// Worded for someone reading about the student, not for the student.
const readiness = computed(() => describeReadiness(profile.value?.predictive_model, 'staff'))

const trendPoints = computed(() => (
  (profile.value?.history ?? [])
    .filter((attempt) => attempt.score_percent !== null && attempt.score_percent !== undefined)
    .map((attempt) => ({
      id: attempt.attempt_id,
      // The chart prints `sublabel` under each dot and uses `label` on hover.
      label: attempt.title || attempt.subject || attempt.label,
      value: Number(attempt.score_percent),
      sublabel: attempt.label,
    }))
))

const initials = computed(() => {
  const parts = String(student.value.name ?? '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

const YEAR_LEVELS = { 1: '1st Year', 2: '2nd Year', 3: '3rd Year', 4: '4th Year' }
const yearLevelLabel = computed(() => YEAR_LEVELS[Number(student.value.year_level)] ?? 'Year level not set')

const statusLabel = computed(() => {
  const status = String(student.value.student_status ?? '').replace(/_/g, ' ').trim()
  return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Status not set'
})

function formatScore(value) {
  return value === null || value === undefined ? 'N/A' : `${Number(value).toFixed(1)}%`
}

function formatWholeScore(value) {
  return value === null || value === undefined ? 'N/A' : `${Math.round(Number(value))}%`
}

function clampPercent(value) {
  return Math.max(0, Math.min(100, Number(value ?? 0)))
}

// Same bands the rest of the app uses: at or above the 75% pass mark, within
// reach of it, or well below.
function scoreTone(value) {
  if (value === null || value === undefined) return 'none'

  const score = Number(value)
  if (score >= 75) return 'good'
  if (score >= 50) return 'mid'
  return 'low'
}

function planContext(item) {
  const itemsSeen = `${item.attempted_count} ${item.attempted_count === 1 ? 'question' : 'questions'} answered`
  const parts = item.level === 'subtopic' && item.topic
    ? [item.subject, item.topic, itemsSeen]
    : [item.subject, itemsSeen]

  return parts.filter(Boolean).join(' · ')
}

function attemptContext(attempt) {
  return [
    attempt.subject,
    attempt.room?.name,
    attempt.submitted_at ? formatCompactDate(attempt.submitted_at) : null,
  ].filter(Boolean).join(' · ')
}

async function loadProfile() {
  loading.value = true
  errorMessage.value = ''

  try {
    const { data } = await studentsApi.profile(route.params.studentId)
    profile.value = data
  } catch (error) {
    profile.value = null
    errorMessage.value = error.response?.status === 404
      ? 'That student could not be found.'
      : (error.response?.data?.message ?? 'Could not load this profile. Try again shortly.')
  } finally {
    loading.value = false
  }
}

// Read again when someone comes back to this page, quietly: the profile on
// screen stays until the new one is in, and a failure changes nothing.
useLiveRefresh(async () => {
  const studentId = route.params.studentId
  const { data } = await studentsApi.profile(studentId)

  if (route.params.studentId === studentId) profile.value = data
}, {
  when: () => !loading.value && profile.value !== null,
})

// Also reloads when moving straight from one student's profile to another's.
watch(() => route.params.studentId, loadProfile, { immediate: true })
</script>

<style scoped>
/*
 * Same visual language as the student dashboard (student/dashboardHome.css):
 * a navy panel for who this is, then white cards with large radii.
 */
.profile-page {
  gap: 16px;
}

.profile-back {
  justify-self: start;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--dk-fg-lnu-navy-light, var(--lnu-navy-light));
  text-decoration: none;
}

.profile-back:hover {
  text-decoration: underline;
}

/* The way back was a 20px-tall line of text on a touch screen. */
@media (pointer: coarse) {
  .profile-back {
    min-height: 44px;
    margin-block: -8px;
  }
}

/* ---------- Loading / error / empty ---------- */

.profile-state {
  display: grid;
  justify-items: center;
  gap: 10px;
  padding: 48px 20px;
  border-radius: 20px;
  border: 1px dashed var(--dk-line-1a237e-a16, rgba(26, 35, 126, 0.16));
  background: var(--surface);
  color: var(--lnu-text-muted);
  text-align: center;
}

.profile-state strong {
  font-size: 16px;
  font-weight: 600;
  color: var(--dk-fg-0b0d17, #0b0d17);
}

.profile-state p {
  margin: 0;
  max-width: 44ch;
  font-size: 13.5px;
  line-height: 1.6;
}

/* ---------- Who this is ---------- */

.profile-hero {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 26px 28px;
  border-radius: 22px;
  color: var(--dk-fg-ffffff, #fff);
  background: var(--panel-navy);
  box-shadow: var(--shadow-md);
}

.profile-avatar {
  flex-shrink: 0;
  width: 68px;
  height: 68px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--dk-bg-lnu-gold-light, var(--lnu-gold-light));
  color: var(--lnu-navy-deep);
  font-size: 24px;
  font-weight: 600;
}

.profile-hero-copy {
  flex: 1;
  min-width: 0;
}

.profile-name {
  margin: 0;
  font-size: clamp(20px, 2.2vw, 26px);
  font-weight: 600;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.profile-contact {
  margin: 4px 0 0;
  display: flex;
  flex-wrap: wrap;
  gap: 2px 14px;
  font-size: 13.5px;
  color: var(--dk-fg-ffffff-a80, rgba(255, 255, 255, 0.8));
  overflow-wrap: anywhere;
}

.profile-chips {
  list-style: none;
  margin: 14px 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.profile-chips li {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 11px;
  border-radius: 999px;
  border: 1px solid var(--dk-line-ffffff-a16, rgba(255, 255, 255, 0.16));
  background: var(--dk-bg-ffffff-a07, rgba(255, 255, 255, 0.07));
  font-size: 12px;
  font-weight: 500;
  color: var(--dk-fg-ffffff-a92, rgba(255, 255, 255, 0.92));
}

.profile-chips svg {
  color: var(--dk-fg-lnu-gold-light, var(--lnu-gold-light));
}

.profile-chips li.is-warning {
  border-color: var(--dk-line-f0d080-a50, rgba(240, 208, 128, 0.5));
  color: var(--dk-fg-lnu-gold-light, var(--lnu-gold-light));
}

.profile-refresh {
  flex-shrink: 0;
  align-self: flex-start;
  width: 44px;
  height: 44px;
  border-radius: 10px;
  border: 1px solid var(--dk-line-ffffff-a22, rgba(255, 255, 255, 0.22));
  background: var(--dk-bg-ffffff-a06, rgba(255, 255, 255, 0.06));
  color: var(--dk-fg-ffffff, #fff);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.profile-refresh:hover:not(:disabled) {
  background: var(--dk-bg-ffffff-a16, rgba(255, 255, 255, 0.16));
}

.profile-refresh:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.profile-refresh:focus-visible {
  outline: 2px solid var(--dk-line-lnu-gold-light, var(--lnu-gold-light));
  outline-offset: 2px;
}

/* ---------- Cards ---------- */

/* stretch: the two cards in a row end on the same line. */
.profile-top-grid,
.profile-bottom-grid {
  display: grid;
  gap: 16px;
  align-items: stretch;
}

.profile-top-grid {
  grid-template-columns: minmax(300px, 0.8fr) minmax(0, 1.2fr);
}

.profile-bottom-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.profile-card {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 22px;
  border-radius: 20px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
}

.profile-card-head h3 {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 17px;
  font-weight: 600;
  color: var(--dk-fg-0b0d17, #0b0d17);
}

.profile-card-head h3 svg {
  flex-shrink: 0;
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
}

.profile-card-head p {
  margin: 3px 0 0;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

/* The gauge takes the spare height and centres in it. */
.profile-outcome {
  flex: 1;
  align-content: center;
  --gauge-max: 250px;
}

.profile-empty {
  margin: 0;
  padding: 18px;
  border-radius: 14px;
  background: var(--surface-muted);
  font-size: 13.5px;
  line-height: 1.6;
  color: var(--lnu-text-muted);
}

/* ---------- Score trend ---------- */

.profile-trend-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.profile-stat {
  display: grid;
  gap: 2px;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid var(--border-soft);
  background: var(--surface-muted);
}

.profile-stat span {
  font-size: 11.5px;
  font-weight: 500;
  color: var(--lnu-text-muted);
}

.profile-stat strong {
  font-size: 18px;
  font-weight: 600;
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
}

.profile-trend-chart {
  flex: 1;
  min-height: 150px;
  display: flex;
  align-items: center;
}

/* ---------- Subjects ---------- */

.profile-subjects {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.profile-subject {
  min-width: 0;
  display: grid;
  gap: 8px;
  padding: 14px;
  border-radius: 14px;
  border: 1px solid var(--border-soft);
}

.profile-subject-top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
}

.profile-subject-top strong {
  min-width: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--dk-fg-0b0d17, #0b0d17);
}

.profile-subject-score,
.profile-row-score {
  flex-shrink: 0;
  font-size: 15px;
  font-weight: 600;
}

/* Amber rather than red for a low score, as on the student's own pages. */
.is-good { color: var(--dk-fg-lnu-success, var(--lnu-success)); }
.is-mid { color: var(--dk-fg-6b5310, #6b5310); }
.is-low { color: var(--dk-fg-9a6a0c, #9a6a0c); }
.is-none { color: var(--lnu-text-muted); }

.profile-bar {
  height: 8px;
  border-radius: 999px;
  background: var(--dk-bg-0d1547-a09, rgba(13, 21, 71, 0.09));
  overflow: hidden;
}

.profile-bar-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
}

.profile-bar-fill.is-good { background: var(--dk-bg-lnu-success, var(--lnu-success)); }
.profile-bar-fill.is-mid { background: var(--dk-bg-c9a84c, #c9a84c); }
.profile-bar-fill.is-low { background: var(--dk-bg-b7791f, #b7791f); }

.profile-subject-meta {
  margin: 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
}

/* ---------- Lists ---------- */

.profile-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 10px;
}

.profile-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 14px;
  border: 1px solid var(--border-soft);
}

.profile-rank {
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--dk-bg-lnu-navy, var(--lnu-navy));
  color: var(--dk-fg-lnu-gold-light, var(--lnu-gold-light));
  font-size: 13px;
  font-weight: 600;
}

.profile-row-copy {
  flex: 1;
  min-width: 0;
  display: grid;
  gap: 2px;
}

.profile-row-copy strong {
  font-size: 14px;
  font-weight: 600;
  color: var(--dk-fg-0b0d17, #0b0d17);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-row-copy span {
  font-size: 12.5px;
  color: var(--lnu-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ---------- Narrower screens ---------- */

@media (max-width: 1100px) {
  .profile-top-grid,
  .profile-bottom-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .profile-subjects {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .profile-hero {
    flex-wrap: wrap;
    gap: 14px;
    padding: 20px 18px;
    border-radius: 20px;
  }

  .profile-avatar {
    width: 54px;
    height: 54px;
    font-size: 19px;
  }

  /* Avatar and refresh share the first line; the details take the next. */
  .profile-hero-copy {
    order: 3;
    flex-basis: 100%;
  }

  .profile-refresh {
    margin-left: auto;
    align-self: center;
  }

  .profile-card {
    padding: 18px 16px;
    border-radius: 18px;
  }

  .profile-subjects {
    grid-template-columns: minmax(0, 1fr);
  }

  .profile-row-copy strong,
  .profile-row-copy span {
    white-space: normal;
  }
}
</style>
