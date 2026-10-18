<template>
  <section class="dashboard-view analytics-page">
    <div v-if="analyticsError" class="feedback danger" role="alert">
      <AlertCircle :size="15" />
      <span>{{ analyticsError }}</span>
    </div>

    <!-- Row 1 -- brand panel with the section tabs under it, beside the one
         number this page is really about -->
    <div class="analytics-hero-row">
      <div class="analytics-hero-col">
        <article class="analytics-hero-card">
          <h1 class="analytics-hero-title">Analytics</h1>
          <p class="analytics-hero-subtitle">
            Track Your Performance
            <InfoTip on-dark text="Your scores and subject mastery across all your exams." />
          </p>
        </article>

        <!-- One section of the page at a time, instead of all three stacked
             down a long scroll. -->
        <div class="analytics-tabs" role="tablist" aria-label="Analytics sections">
          <button
            v-for="tab in tabs"
            :id="`analytics-tab-${tab.key}`"
            :key="tab.key"
            type="button"
            role="tab"
            class="analytics-tab"
            :class="{ active: activeTab === tab.key }"
            :aria-selected="activeTab === tab.key"
            aria-controls="analytics-panel"
            @click="activeTab = tab.key"
          >
            <component :is="tab.icon" :size="15" />
            <span>{{ tab.label }}</span>
          </button>
        </div>
      </div>

      <article class="analytics-overview-card">
        <div class="analytics-overview-body">
          <div class="analytics-overview-primary">
            <div class="analytics-overview-labelrow">
              <span class="analytics-label"><TrendingUp :size="12" />Overall Average</span>
              <span class="info-hint" tabindex="0" :aria-label="overviewDescription">
                <Info :size="12" />
                <span class="info-hint-bubble" role="tooltip">{{ overviewDescription }}</span>
              </span>
              <button
                type="button"
                class="analytics-refresh-btn"
                :disabled="analyticsLoading"
                title="Refresh analytics"
                aria-label="Refresh analytics"
                @click="loadAnalytics"
              >
                <RefreshCw :size="12" :class="{ 'spin-soft': analyticsLoading }" />
              </button>
            </div>

            <strong class="analytics-overview-value">{{ averageScore === null ? '--' : Math.round(averageScore) }}%</strong>
            <span class="analytics-overview-sublabel">Current Level</span>
          </div>

          <div class="analytics-overview-chart">
            <ScoreTrendChart
              v-if="trendPoints.length > 0"
              :points="trendPoints"
              :threshold="stats.passing_threshold"
              variant="compact"
            />
            <div v-else class="analytics-overview-chart-empty">
              <ClipboardList :size="18" />
              <p>Your score trend appears here once you finish an exam.</p>
            </div>
          </div>

          <div class="analytics-overview-boxes">
            <div class="analytics-overview-box">
              <span class="analytics-overview-box-label">Status</span>
              <span class="analytics-overview-box-value">
                <component :is="studyStatus.icon" :size="13" />{{ studyStatus.label }}
              </span>
            </div>
            <div class="analytics-overview-box">
              <span class="analytics-overview-box-label">Exams Taken</span>
              <span class="analytics-overview-box-value">
                <TrendingUp :size="13" />{{ stats.completed_attempts }}
              </span>
            </div>
          </div>
        </div>

        <p class="analytics-overview-footnote">
          Your average score is calculated across <strong>{{ stats.completed_attempts }} {{ stats.completed_attempts === 1 ? 'examination' : 'examinations' }}</strong>.
          <template v-if="averageScore !== null">
            You are currently
            <strong :class="isOnTarget ? 'metric-success' : 'metric-danger'">
              {{ Math.abs(Math.round(averageScore - stats.passing_threshold)) }}% {{ isOnTarget ? 'above' : 'away' }}
            </strong>
            {{ isOnTarget ? 'the recommended passing threshold.' : 'from the recommended passing threshold.' }}
          </template>
        </p>
      </article>
    </div>

    <div id="analytics-panel" class="analytics-panel" role="tabpanel" :aria-labelledby="`analytics-tab-${activeTab}`">
        <!-- Subjects -->
        <section v-show="activeTab === 'subjects'" class="analytics-section" aria-labelledby="analytics-subjects-heading">
          <header class="analytics-section-head">
            <h2 id="analytics-subjects-heading" class="analytics-section-title">Subjects</h2>
            <span class="info-hint" tabindex="0" :aria-label="subjectsDescription">
              <Info :size="12" />
              <span class="info-hint-bubble" role="tooltip">{{ subjectsDescription }}</span>
            </span>
            <span class="analytics-section-count">{{ subjects.length }}</span>
          </header>

          <div v-if="isInitialLoading" class="analytics-empty">
            <RefreshCw :size="22" class="spin-soft" />
            <p>Loading your subject breakdown...</p>
          </div>

          <div v-else class="subject-grid">
            <article v-for="subject in subjects" :key="subject.label" class="subject-card">
              <div class="subject-card-top">
                <span class="subject-card-icon" aria-hidden="true">
                  <component :is="subjectIcon(subject.label)" :size="14" />
                </span>
                <div class="subject-card-copy">
                  <strong class="subject-card-name">{{ subject.label }}</strong>
                  <p class="subject-card-count">{{ subject.attempts_count }} {{ subject.attempts_count === 1 ? 'exam' : 'exams' }}</p>
                </div>
                <strong class="subject-card-score" :class="textToneClass(subject.scoreTone)">
                  {{ formatScore(subject.average_score_percent) }}
                </strong>
              </div>

              <div
                class="score-track"
                role="img"
                :aria-label="`${subject.label}: ${formatScore(subject.average_score_percent)} average, passing mark ${stats.passing_threshold}%`"
              >
                <div
                  class="score-track-fill"
                  :class="barClass(subject.scoreTone)"
                  :style="{ width: `${clampPercent(subject.coveragePercent)}%` }"
                />
              </div>

              <div class="subject-card-meta">
                <template v-if="subject.attempts_count === 0">
                  <span class="subject-card-meta-empty">No finished exam yet</span>
                </template>
                <template v-else>
                  <span class="subject-card-meta-facts">
                    <span>Last: <strong>{{ formatShortScore(subject.latest_score_percent) }}</strong></span>
                    <span>Best: <strong>{{ formatShortScore(subject.best_score_percent) }}</strong></span>
                  </span>
                  <span class="subject-card-trend" :class="trendToneClass(subject)">{{ trendLabel(subject) }}</span>
                </template>
              </div>
            </article>
          </div>
        </section>

        <!-- Study plan -->
        <section v-show="activeTab === 'plan'" class="analytics-section" aria-labelledby="analytics-plan-heading">
          <header class="analytics-section-head">
            <h2 id="analytics-plan-heading" class="analytics-section-title">Your Study Plan</h2>
            <span class="info-hint" tabindex="0" :aria-label="studyPlanDescription">
              <Info :size="12" />
              <span class="info-hint-bubble" role="tooltip">{{ studyPlanDescription }}</span>
            </span>
            <span class="analytics-section-count">
              {{ studyPlan.length }} focus {{ studyPlan.length === 1 ? 'area' : 'areas' }}
            </span>
          </header>

          <div v-if="isInitialLoading" class="analytics-empty">
            <RefreshCw :size="22" class="spin-soft" />
            <p>Working out what to study next...</p>
          </div>

          <div v-else-if="studyPlan.length === 0" class="analytics-empty">
            <component :is="hasAttemptData ? ShieldCheck : ClipboardList" :size="22" />
            <h3>{{ hasAttemptData ? 'Nothing flagged right now' : 'No study plan yet' }}</h3>
            <p>
              {{
                hasAttemptData
                  ? `Every topic you've answered questions in is already at or above the ${stats.passing_threshold}% passing mark.`
                  : 'Finish an exam and your weakest topics get ranked here, worst first.'
              }}
            </p>
          </div>

          <ol v-else class="plan-list">
            <li v-for="item in studyPlan" :key="`${item.level}-${item.subtopic_id ?? item.topic_id}-${item.rank}`" class="plan-card">
              <span class="plan-rank" aria-hidden="true">{{ item.rank }}</span>

              <div class="plan-copy">
                <strong class="plan-title">{{ item.label }}</strong>
                <p class="plan-sub">{{ planContext(item) }}</p>
              </div>

              <strong class="plan-score" :class="textToneClass(item.scoreTone)">{{ formatShortScore(item.score) }}</strong>

              <RouterLink
                v-if="item.review_ready"
                class="plan-review-btn"
                :to="{ name: 'dashboard-review-bot', query: item.reviewQuery }"
                :aria-label="`Practice ${item.label} in the Review Bot`"
              >
                Review
                <ArrowRight :size="13" />
              </RouterLink>
              <span v-else class="plan-review-empty" title="Your teacher has not published Review Bot questions for this area yet.">
                No set yet
              </span>
            </li>
          </ol>
        </section>

        <!-- Exam history: every sitting, so a retake can be set beside the
             attempt before it. The figures above use the latest of each. -->
        <section v-show="activeTab === 'history'" class="analytics-section" aria-labelledby="analytics-history-heading">
          <header class="analytics-section-head">
            <h3 id="analytics-history-heading">
              Exam History
              <InfoTip text="Every exam you have submitted, newest first. Where you took an exam twice, your latest attempt is the score that counts." />
            </h3>
          </header>

          <div v-if="examHistory.length === 0" class="analytics-empty">
            <ClipboardList :size="22" />
            <h3>No exams submitted yet</h3>
            <p>Each exam you finish is listed here with its score.</p>
          </div>

          <template v-else>
            <ul class="exam-history">
              <li v-for="row in visibleHistory" :key="row.attempt_id" class="exam-history-row" :class="{ 'is-earlier': !row.counts }">
                <span class="exam-history-copy">
                  <strong>{{ row.title }}</strong>
                  <span>{{ row.subject }} &middot; {{ row.room?.name || 'Unknown room' }}</span>
                  <span>
                    {{ formatDateTime(row.submitted_at) }}
                    <template v-if="row.attempts_total > 1">
                      &middot; Attempt {{ row.attempt_number }} of {{ row.attempts_total }}
                    </template>
                  </span>
                </span>

                <span class="exam-history-score">
                  <strong :class="historyTone(row)">{{ historyScore(row) }}</strong>
                  <span v-if="row.attempts_total > 1" class="pill" :class="row.counts ? 'navy' : 'neutral'">
                    {{ row.counts ? 'Counts' : 'Earlier Attempt' }}
                  </span>
                </span>

                <RouterLink class="ghost-btn exam-history-link" :to="{ name: 'dashboard-exam-result', params: { attemptId: row.attempt_id } }">
                  View Result
                </RouterLink>
              </li>
            </ul>

            <button v-if="examHistory.length > historyShown" type="button" class="ghost-btn exam-history-more" @click="historyShown += HISTORY_PAGE">
              Show More ({{ examHistory.length - historyShown }} left)
            </button>
          </template>
        </section>

      <!-- Exam outcome beside what to review next -->
      <div v-show="activeTab === 'readiness'" class="analytics-readiness-grid">
        <!-- Estimated outcome: the same card as the dashboard's -->
        <article class="analytics-card analytics-prediction-card" aria-labelledby="analytics-let-heading">
          <header class="analytics-card-head is-stacked">
            <h2 id="analytics-let-heading" class="analytics-card-title">Estimated Outcome</h2>
            <p class="analytics-card-sub">From your latest complete semester</p>
          </header>

          <EstimatedOutcome
            :readiness="readiness"
            :average-score-display="averageScore === null ? 'N/A' : `${averageScore.toFixed(1)}%`"
            :focus-subjects-count="stats.focus_subjects_count"
          />

        </article>

        <!-- What to review next -->
        <article class="analytics-card analytics-guidance-card" aria-labelledby="analytics-review-heading">
          <header class="analytics-card-head is-stacked">
            <h2 id="analytics-review-heading" class="analytics-card-title">
              What To Review Next
              <InfoTip text="Do these in order. Each one opens a short practice set on that topic." />
            </h2>
          </header>

          <div v-if="isInitialLoading" class="analytics-empty is-inline">
            <RefreshCw :size="20" class="spin-soft" />
            <p>Loading your study notes...</p>
          </div>

          <p v-else-if="reviewNarrative.empty" class="guidance-empty">{{ reviewNarrative.emptyMessage }}</p>

          <template v-else>
            <!--
              Numbered steps, each one a topic with a one-line reason and a
              button into a practice set that exists. The narrative lead below
              is the fallback for when no step could be assembled.
            -->
            <NextStepsList v-if="actionPlan.length > 0" :actions="actionPlan" cta-label="Practice now" />

            <div v-else-if="reviewNarrative.headline" class="guidance-lead">
              <div class="guidance-lead-head">
                <span class="guidance-lead-chip" aria-hidden="true">
                  <component :is="guidanceStyle(reviewNarrative.headline.key).icon" :size="15" />
                </span>
                <span class="guidance-kicker">{{ reviewNarrative.headline.label }}</span>
              </div>
              <p class="guidance-lead-text">{{ reviewNarrative.headline.text }}</p>

              <!--
                The knowledge-tracing estimate behind the recommendation. Shown
                rather than hidden because it is the number the ranking is
                actually made on, and because the denominator underneath it
                tells the student how much the system has to go on.
              -->
              <div v-if="reviewNarrative.headline.mastery" class="mastery-readout">
                <div class="mastery-head">
                  <span class="mastery-label">Estimated grasp</span>
                  <strong class="mastery-value" :class="`is-${reviewNarrative.headline.mastery.band}`">
                    {{ reviewNarrative.headline.mastery.percent }}%
                  </strong>
                </div>

                <div
                  class="mastery-track"
                  role="img"
                  :aria-label="`Estimated grasp ${reviewNarrative.headline.mastery.percent} percent, based on ${reviewNarrative.headline.mastery.attempted} answers`"
                >
                  <span
                    class="mastery-fill"
                    :class="`is-${reviewNarrative.headline.mastery.band}`"
                    :style="{ width: `${Math.max(reviewNarrative.headline.mastery.percent, 2)}%` }"
                  />
                </div>

                <p class="mastery-foot">
                  <component :is="trendStyle(reviewNarrative.headline.mastery.trend).icon" :size="12" />
                  <span>{{ trendStyle(reviewNarrative.headline.mastery.trend).label }}</span>
                  <span class="mastery-dot" aria-hidden="true">·</span>
                  <span>from {{ reviewNarrative.headline.mastery.attempted }} answered {{ reviewNarrative.headline.mastery.attempted === 1 ? 'item' : 'items' }}</span>
                </p>
              </div>

              <RouterLink
                v-if="reviewNarrative.headline.reviewQuery"
                class="guidance-cta"
                :to="{ name: 'dashboard-review-bot', query: reviewNarrative.headline.reviewQuery }"
              >
                Start a review session
                <ArrowRight :size="14" />
              </RouterLink>
            </div>

            <ul v-if="reviewNarrative.points.length > 0" class="guidance-points">
              <li
                v-for="point in reviewNarrative.points"
                :key="point.key ?? point.label"
                class="guidance-point"
                :class="`is-${guidanceStyle(point.key).tone}`"
              >
                <span class="guidance-point-chip" aria-hidden="true">
                  <component :is="guidanceStyle(point.key).icon" :size="13" />
                </span>
                <div class="guidance-point-copy">
                  <span class="guidance-point-label">{{ point.label }}</span>
                  <p class="guidance-point-text">{{ point.text }}</p>
                </div>
              </li>
            </ul>
          </template>
        </article>
      </div>
    </div>
  </section>
</template>

<script setup>
import InfoTip from '@/components/InfoTip.vue'
import { computed, ref } from 'vue'
import {
  AlertCircle,
  AlertTriangle,
  ArrowRight,
  BarChart3,
  BookMarked,
  BookOpen,
  Building2,
  ChevronDown,
  ClipboardList,
  Clock,
  History,
  Info,
  Laptop,
  LayoutGrid,
  ListOrdered,
  ListTree,
  Minus,
  PackageSearch,
  RefreshCw,
  ShieldCheck,
  Target,
  TrendingDown,
  TrendingUp,
} from 'lucide-vue-next'
import { useStudentInsightsModule } from '../composables/useStudentInsightsModule'
import { describeReadiness } from '../composables/readiness'
import { formatDateTime } from '../composables/useDashboardFormatters'
import ScoreTrendChart from '@/components/charts/ScoreTrendChart.vue'
import EstimatedOutcome from '@/components/EstimatedOutcome.vue'
import NextStepsList from '@/components/NextStepsList.vue'

const {
  analyticsLoading,
  analyticsLoaded,
  analyticsError,
  hasAttemptData,
  stats,
  subjects,
  averageScore,
  reviewNarrative,
  scoreHistory,
  studyPlan,
  actionPlan,
  predictivePanel,
  loadAnalytics,
  examHistory,
} = useStudentInsightsModule()

const HISTORY_PAGE = 20
const historyShown = ref(HISTORY_PAGE)
const visibleHistory = computed(() => examHistory.value.slice(0, historyShown.value))

function historyScore(row) {
  return row.score_percent === null || row.score_percent === undefined ? 'Not graded' : `${Number(row.score_percent).toFixed(1)}%`
}

// Indigo for passing and amber for below, the pair the charts use.
function historyTone(row) {
  if (row.score_percent === null || row.score_percent === undefined) return 'is-ungraded'

  return Number(row.score_percent) >= Number(stats.value.passing_threshold ?? 75) ? 'is-passing' : 'is-below'
}

const overviewDescription = 'The big number is your average across every finished exam. '
  + 'The line plots your last exams in order, oldest to newest (A1 is the oldest) against the gold 75% passing mark -- '
  + 'hover a point to see which exam it was and its exact score.'

const studyPlanDescription = 'Your weakest mapped areas, worst first, based on how you actually answered '
  + 'the individual questions -- not whole exam scores. Only areas below the 75% passing mark are listed. '
  + 'Review opens the Review Bot already filtered to that area.'

const subjectsDescription = 'The percentage is your average score across every finished exam in this subject, '
  + 'and the bar plots it from 0 to 100%. Last = your most recent score, Best = your highest, '
  + 'and the trend compares your two most recent attempts in this subject.'

// Purely decorative (not derived from any data) -- one fixed icon per the
// app's 6 core subjects (see constants/librarySubjects.js), same set used
// site-wide wherever a subject needs a visual anchor.
const SUBJECT_ICONS = {
  'Cataloging and Classification': BookMarked,
  'Indexing and Abstracting': ListTree,
  'Information Technology': Laptop,
  'Reference Services': BookOpen,
  'Library Management': Building2,
  'Selection and Acquisition': PackageSearch,
}

function subjectIcon(label) {
  return SUBJECT_ICONS[label] ?? BarChart3
}

/*
 * Icon + tone per "What To Review Next" slot, keyed on the stable key the
 * composable emits rather than the human label -- the label is display copy
 * and free to be reworded without silently dropping every icon back to the
 * fallback.
 */
const GUIDANCE_STYLES = {
  opportunity: { icon: Target, tone: 'good' },
  on_track: { icon: ShieldCheck, tone: 'good' },
  also_review: { icon: AlertTriangle, tone: 'warn' },
  strength: { icon: TrendingUp, tone: 'good' },
}

function guidanceStyle(key) {
  return GUIDANCE_STYLES[key] ?? { icon: Info, tone: 'neutral' }
}

/*
 * Which way the knowledge-tracing estimate is moving. "unclear" is its own
 * state on purpose -- below a handful of answers the model will not call a
 * direction, and saying "holding steady" there would be inventing confidence
 * it does not have.
 */
const TREND_STYLES = {
  improving: { icon: TrendingUp, label: 'Trending up' },
  slipping: { icon: TrendingDown, label: 'Slipping' },
  steady: { icon: Minus, label: 'Holding steady' },
}

function trendStyle(trend) {
  return TREND_STYLES[trend] ?? { icon: Minus, label: 'Too early to call a trend' }
}

const isOnTarget = computed(() => (
  averageScore.value !== null && averageScore.value >= stats.value.passing_threshold
))

// Plain-language read of the same two facts the card already shows (any
// finished exams at all, and whether the average clears the passing mark),
// so the summary box says where the student stands without a third metric.
const studyStatus = computed(() => {
  if ((stats.value.completed_attempts ?? 0) === 0) {
    return { label: 'Not Started', icon: ClipboardList }
  }

  return isOnTarget.value
    ? { label: 'On Track', icon: ShieldCheck }
    : { label: 'Active Study', icon: Target }
})

const trendPoints = computed(() => (
  scoreHistory.value.map((item) => ({
    id: item.attempt_id,
    label: item.title || item.subject || item.label,
    value: item.score,
    sublabel: item.label,
    tone: item.scoreTone,
  }))
))

const isInitialLoading = computed(() => analyticsLoading.value && !analyticsLoaded.value)

// Same wording as the dashboard's Estimated Outcome card.
const readiness = computed(() => describeReadiness(predictivePanel.value))

// The three sections of the page, shown one at a time.
const tabs = [
  { key: 'subjects', label: 'Subjects', icon: LayoutGrid },
  { key: 'plan', label: 'Study Plan', icon: ListOrdered },
  { key: 'history', label: 'Exam History', icon: History },
  { key: 'readiness', label: 'Exam Outcome', icon: ShieldCheck },
]
const activeTab = ref('subjects')

function clampPercent(value) {
  return Math.max(0, Math.min(100, Number(value ?? 0)))
}

function formatScore(value) {
  return value === null || value === undefined ? '--' : `${Number(value).toFixed(1)}%`
}

/** Whole-number variant for the dense Last/Best meta row, where a decimal buys nothing. */
function formatShortScore(value) {
  return value === null || value === undefined ? '--' : `${Math.round(Number(value))}%`
}

/*
 * Where a study-plan row sits and how much evidence is behind it. A subtopic
 * row names its parent topic too (a subtopic name alone -- "Co-Citation" --
 * often isn't placeable); a topic row would only repeat itself, so it stops
 * at the subject.
 */
function planContext(item) {
  const itemsSeen = `${item.attempted_count} ${item.attempted_count === 1 ? 'item' : 'items'} seen`
  const parts = item.level === 'subtopic' && item.topic
    ? [item.subject, item.topic, itemsSeen]
    : [item.subject, itemsSeen]

  return parts.filter(Boolean).join(' · ')
}

function trendLabel(subject) {
  if (subject.trend === 'up') return `Improving${subject.trend_delta ? ` +${Math.abs(subject.trend_delta).toFixed(1)}` : ''}`
  if (subject.trend === 'down') return `Went down${subject.trend_delta ? ` -${Math.abs(subject.trend_delta).toFixed(1)}` : ''}`
  if (subject.trend === 'steady') return 'Steady'
  return 'No data'
}

function barClass(tone) {
  if (tone === 'success') return 'ok'
  if (tone === 'danger') return 'danger'
  if (tone === 'gold') return 'gold'
  return 'navy'
}

function textToneClass(tone) {
  if (tone === 'success') return 'ok'
  if (tone === 'danger') return 'danger-text'
  if (tone === 'gold') return 'gold-text'
  if (tone === 'navy') return 'navy-text'
  return 'muted-text'
}

/* "Steady" is the absence of movement, not a result worth colouring -- the
   shared trendTone maps it to navy, which in this dense meta row read as a
   third status competing with the improving/declining ones. */
function trendToneClass(subject) {
  if (subject.trend === 'up') return 'ok'
  if (subject.trend === 'down') return 'danger-text'
  return 'muted-text'
}
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.analytics-page {
  --a-ink: #102149;
  --a-muted: #66738c;
  --a-label: #5d6b86;
  --a-card-border: rgba(13, 21, 71, 0.09);
  --a-card-radius: 14px;
  --a-card-shadow: 0 1px 2px rgba(13, 21, 71, 0.05);

  gap: 16px;
}

/* ---- Row 1: brand panel + overall average ---- */
/* The first column is never narrower than the tab bar under the brand panel
   needs for its three labels (nor than 320px); the average card takes the rest. */
.analytics-hero-row {
  display: grid;
  grid-template-columns: minmax(min-content, 0.42fr) minmax(0, 1fr);
  gap: 16px;
  align-items: stretch;
}

/* The brand panel with the tabs under it; together they match the height of
   the overall-average card beside them. */
.analytics-hero-col {
  display: grid;
  grid-template-rows: minmax(0, 1fr) auto;
  gap: 12px;
  min-width: 320px;
}

/* Each tab is as wide as its label and they share what is left over. Three
   equal thirds left "Exam Outcome", the longest label, spilling out of its
   pill while "Subjects" had room to spare. */
.analytics-tabs {
  display: flex;
  gap: 4px;
  padding: 5px;
  border-radius: 14px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
}

.analytics-tab {
  flex: 1 1 auto;
  min-width: 0;
  height: 40px;
  padding: 0 10px;
  border: none;
  border-radius: 10px;
  background: transparent;
  color: var(--lnu-text-muted);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  transition: background 0.15s ease, color 0.15s ease;
}

.analytics-tab svg {
  flex-shrink: 0;
}

/* Last resort on a very narrow screen: shorten the label, never spill it. */
.analytics-tab span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}

.analytics-tab:hover:not(.active) {
  background: var(--surface-muted);
  color: var(--lnu-navy);
}

.analytics-tab.active {
  background: var(--lnu-navy);
  color: var(--lnu-gold-light);
  font-weight: 600;
}

.analytics-tab:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

/* ---- The selected section ---- */
.analytics-panel {
  min-width: 0;
  padding: 20px;
  border-radius: 20px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
}

.analytics-readiness-grid {
  display: grid;
  grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
  gap: 16px;
  /* stretch: Exam Outcome and What To Review Next end on the same line. */
  align-items: stretch;
}

/* Wide screens: the page takes at least the full height under the title and
   the selected section fills whatever the top row leaves, so the white panel
   always reaches the bottom of the screen. A minimum, not a fixed height --
   a long section still grows the page and scrolls normally. */
@media (min-width: 1201px) {
  .analytics-page {
    display: flex;
    flex-direction: column;
    min-height: 100%;
  }

  .analytics-hero-row {
    flex-shrink: 0;
  }

  .analytics-panel {
    flex: 1;
    display: flex;
    flex-direction: column;
  }

  .analytics-panel > * {
    flex: 1;
  }
}

.analytics-hero-card {
  border-radius: var(--radius-lg);
  padding: 24px 26px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 6px;
  background: var(--panel-navy);
  color: #fff;
  box-shadow: 0 10px 24px rgba(13, 21, 71, 0.2);
}

.analytics-hero-title {
  margin: 0;
  font-size: clamp(22px, 2.4vw, 28px);
  font-weight: 800;
  line-height: 1.2;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.analytics-hero-subtitle {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.3;
}


.analytics-overview-card {
  border-radius: var(--radius-md);
  padding: 16px 20px 14px;
  display: grid;
  gap: 12px;
  align-content: center;
  background: linear-gradient(160deg, #e9f7ee 0%, #f4fbf6 52%, #ffffff 100%);
  border: 1px solid rgba(47, 138, 79, 0.2);
  box-shadow: var(--shadow-sm);
}

.analytics-overview-body {
  display: grid;
  grid-template-columns: minmax(120px, auto) minmax(0, 1fr) minmax(132px, auto);
  gap: 18px;
  align-items: center;
}

.analytics-overview-primary {
  display: grid;
  gap: 2px;
  align-content: center;
}

.analytics-overview-labelrow {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-bottom: 2px;
}

.analytics-label {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--a-label);
  white-space: nowrap;
}

.analytics-refresh-btn {
  width: 22px;
  height: 22px;
  border-radius: 6px;
  border: 1px solid rgba(13, 21, 71, 0.14);
  background: rgba(255, 255, 255, 0.8);
  color: var(--a-label);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.analytics-refresh-btn:hover:not(:disabled) {
  background: #fff;
  color: var(--lnu-navy);
}

.analytics-refresh-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.analytics-overview-value {
  font-size: clamp(30px, 3vw, 40px);
  font-weight: 800;
  color: var(--a-ink);
  line-height: 1.2;
}

.analytics-overview-sublabel {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--a-label);
}

.analytics-overview-chart {
  min-width: 0;
}

.analytics-overview-chart-empty {
  display: grid;
  justify-items: center;
  align-content: center;
  gap: 6px;
  min-height: 118px;
  padding: 12px;
  border-radius: 14px;
  border: 1px dashed rgba(13, 21, 71, 0.16);
  color: var(--a-muted);
  text-align: center;
}

.analytics-overview-chart-empty p {
  margin: 0;
  font-size: 11.5px;
  max-width: 28ch;
}

.analytics-overview-boxes {
  display: grid;
  gap: 8px;
}

.analytics-overview-box {
  display: grid;
  gap: 3px;
  padding: 8px 11px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.86);
  border: 1px solid rgba(13, 21, 71, 0.1);
}

.analytics-overview-box-label {
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--a-label);
}

.analytics-overview-box-value {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  font-weight: 800;
  color: var(--a-ink);
  white-space: nowrap;
}

.analytics-overview-footnote {
  margin: 0;
  padding-top: 11px;
  border-top: 1px solid rgba(13, 21, 71, 0.1);
  font-size: 12px;
  line-height: 1.55;
  color: #4f5d78;
}

/* metric-* (not tone-*) on purpose -- dashboard.css's shared .tone-success/
   .tone-danger utility also carries a pill background, meant for actual
   badges elsewhere in the app. This is plain colored text; a distinct
   class name avoids inheriting that background instead of fighting it. */
.analytics-overview-footnote strong.metric-success { color: #226b3f; }
.analytics-overview-footnote strong.metric-danger { color: #b03a33; }

/* A bare section label over loose cards (not another card wrapper) -- one
   less nested box between the page and the data, and it keeps the two
   tinted cards in the right column reading as the only "panels" here. */
.analytics-section-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 10px;
  padding: 0 2px;
}

.analytics-section-title {
  margin: 0;
  font-size: 12.5px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--a-ink);
}

.analytics-section-count {
  margin-left: auto;
  font-size: 12px;
  font-weight: 700;
  color: var(--a-muted);
}

/* ---- Shared progress track ---- */
.score-track {
  height: 5px;
  border-radius: 999px;
  background: rgba(13, 21, 71, 0.08);
  overflow: hidden;
}

.score-track-fill {
  height: 100%;
  border-radius: 999px;
  transition: width 0.35s ease;
}

.score-track-fill.navy { background: #315cbf; }
.score-track-fill.ok { background: #2f8a4f; }
.score-track-fill.gold { background: linear-gradient(90deg, #e3ae40, #c9902a); }
.score-track-fill.danger { background: #e0453f; }

/* ---- Subjects ---- */
.subject-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  /* start (not the stretch default) -- two subjects side by side can have
     different name lengths/wrap heights, and stretch would force the
     shorter card to match the taller one's height. */
  align-items: start;
  gap: 10px;
}

.subject-card {
  display: flex;
  flex-direction: column;
  gap: 9px;
  padding: 13px 14px;
  background: #fff;
  border: 1px solid var(--a-card-border);
  border-radius: var(--a-card-radius);
  box-shadow: var(--a-card-shadow);
}

.subject-card-top {
  display: flex;
  align-items: flex-start;
  gap: 9px;
}

.subject-card-icon {
  flex-shrink: 0;
  width: 26px;
  height: 26px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(var(--lnu-info-rgb), 0.09);
  color: var(--lnu-info);
}

.subject-card-copy {
  flex: 1;
  min-width: 0;
}

.subject-card-name {
  display: block;
  font-size: 13px;
  font-weight: 700;
  line-height: 1.3;
  color: var(--a-ink);
}

.subject-card-count {
  margin: 2px 0 0;
  font-size: 11px;
  color: var(--a-muted);
}

.subject-card-score {
  flex-shrink: 0;
  font-size: 15px;
  font-weight: 800;
  line-height: 1.2;
}

.subject-card-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.subject-card-meta-facts {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
  color: var(--a-muted);
}

.subject-card-meta-facts strong {
  font-weight: 800;
  color: var(--a-ink);
}

.subject-card-meta-empty {
  color: var(--a-muted);
  font-weight: 700;
}

.subject-card-trend {
  flex-shrink: 0;
  text-align: right;
}

/* ---- Study plan ---- */
.plan-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 10px;
  counter-reset: none;
}

.plan-card {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto auto;
  grid-template-areas: "rank copy score action";
  align-items: center;
  gap: 6px 12px;
  padding: 12px 14px;
  background: #fff;
  border: 1px solid var(--a-card-border);
  border-radius: var(--a-card-radius);
  box-shadow: var(--a-card-shadow);
}

/* The top row is the "start here" one, so its rank reads as a filled marker
   while the rest stay quiet outlines -- the ordering alone is easy to skim
   past when every badge looks identical. */
.plan-rank {
  grid-area: rank;
  flex-shrink: 0;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(13, 21, 71, 0.08);
  color: var(--a-ink);
  font-size: 11px;
  font-weight: 800;
}

.plan-card:first-child .plan-rank {
  background: var(--a-ink);
  color: #fff;
}

.plan-copy {
  grid-area: copy;
  min-width: 0;
}

.plan-title {
  display: block;
  font-size: 13.5px;
  font-weight: 700;
  line-height: 1.35;
  color: var(--a-ink);
}

.plan-sub {
  margin: 3px 0 0;
  font-size: 11.5px;
  color: var(--a-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.plan-score {
  grid-area: score;
  font-size: 15px;
  font-weight: 800;
  line-height: 1.2;
}

.plan-review-btn {
  grid-area: action;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  height: 30px;
  padding: 0 13px;
  border-radius: 8px;
  background: linear-gradient(135deg, var(--lnu-navy), var(--lnu-navy-light));
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  text-decoration: none;
  white-space: nowrap;
  transition: background 0.15s ease;
}

.plan-review-btn:hover {
  background: linear-gradient(135deg, var(--lnu-navy-light), var(--lnu-navy));
}

.plan-review-btn:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

.plan-review-empty {
  grid-area: action;
  padding: 0 4px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--a-muted);
  white-space: nowrap;
  cursor: help;
}

/* ---- Right-column cards ---- */
.analytics-card {
  border-radius: var(--a-card-radius);
  padding: 16px 17px;
  display: grid;
  gap: 12px;
  align-content: start;
}

.analytics-prediction-card {
  background: var(--surface);
  border: 1px solid var(--border-soft);
  /* Lets the gauge grow a little in the wide two-column layout. */
  --gauge-max: 260px;
}

.analytics-guidance-card {
  background: linear-gradient(165deg, #e9f8ee 0%, #f3fbf5 55%, #fafffb 100%);
  border: 1px solid rgba(47, 138, 79, 0.22);
}

.analytics-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.analytics-card-head.is-stacked {
  flex-direction: column;
  gap: 0;
}

.analytics-card-title {
  margin: 0;
  font-size: 15px;
  font-weight: 800;
  color: var(--a-ink);
}

.analytics-card-sub {
  margin: 3px 0 0;
  font-size: 11.5px;
  line-height: 1.45;
  color: var(--a-muted);
}

/* ---- What to review next ---- */
.analytics-guidance-card {
  gap: 13px;
  padding: 17px 18px 18px;
}

/* The lead recommendation: solid white against the card's tinted ground, so
   it sits forward of the supporting notes on material alone -- no extra
   badge or heavier border needed to say "this one first". */
.guidance-lead {
  position: relative;
  padding: 14px 15px 15px 18px;
  border-radius: 14px;
  background: #fff;
  border: 1px solid rgba(47, 138, 79, 0.2);
  box-shadow: 0 2px 6px rgba(13, 21, 71, 0.05);
  overflow: hidden;
}

.guidance-lead::before {
  content: '';
  position: absolute;
  inset: 0 auto 0 0;
  width: 3px;
  background: linear-gradient(180deg, #2f8a4f, #1b6b41);
}

.guidance-lead-head {
  display: flex;
  align-items: center;
  gap: 9px;
  margin-bottom: 9px;
}

.guidance-lead-chip {
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  border-radius: 9px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(140deg, rgba(47, 138, 79, 0.18), rgba(120, 200, 150, 0.3));
  color: #1b6b41;
}

.guidance-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: #1b6b41;
}

/* Full width under the chip rather than in a column beside it -- this
   sentence names a topic and a score and routinely runs three lines, and a
   narrow column made it ragged. */
.guidance-lead-text {
  margin: 0;
  font-size: 13.5px;
  font-weight: 500;
  line-height: 1.6;
  color: #1c2b4f;
  overflow-wrap: break-word;
}

/* ---- Knowledge-tracing readout ---- */

.mastery-readout {
  margin-top: 13px;
  padding-top: 12px;
  border-top: 1px dashed rgba(47, 138, 79, 0.22);
}

.mastery-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 6px;
}

.mastery-label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: #5c6b8a;
}

.mastery-value {
  font-size: 15px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

/* Three bands rather than a continuous gradient: the estimate is not precise
   enough to justify shading every percentage point differently. */
.mastery-value.is-struggling { color: var(--lnu-danger); }
.mastery-value.is-developing { color: #b07d1a; }
.mastery-value.is-mastered { color: #1b6b41; }

.mastery-track {
  height: 6px;
  border-radius: 999px;
  background: rgba(13, 21, 71, 0.09);
  overflow: hidden;
}

.mastery-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  transition: width 0.3s ease;
}

.mastery-fill.is-struggling { background: linear-gradient(90deg, #d4574f, var(--lnu-danger)); }
.mastery-fill.is-developing { background: linear-gradient(90deg, #e0b055, #b07d1a); }
.mastery-fill.is-mastered { background: linear-gradient(90deg, #4fa86f, #1b6b41); }

.mastery-foot {
  display: flex;
  align-items: center;
  gap: 5px;
  margin: 7px 0 0;
  font-size: 11.5px;
  font-weight: 500;
  color: #5c6b8a;
  flex-wrap: wrap;
}

.mastery-dot {
  opacity: 0.5;
}

.guidance-cta {
  margin-top: 13px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: var(--control-radius, 9px);
  background: var(--lnu-navy);
  color: #fff;
  font-size: 12.5px;
  font-weight: 700;
  text-decoration: none;
  transition: background 0.2s ease, box-shadow 0.2s ease;
}

.guidance-cta:hover {
  background: var(--lnu-navy-light, #2a35a0);
  box-shadow: 0 6px 14px rgba(26, 35, 126, 0.22);
}

.guidance-cta:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

/* The numbered steps are styled in components/NextStepsList.vue. */

.guidance-points {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.62);
  border: 1px solid rgba(47, 138, 79, 0.14);
  overflow: hidden;
}

.guidance-point {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 13px;
}

/* Hairlines between the supporting notes, not a box around each -- they
   should read as one quiet group under the lead rather than three more
   cards competing with it. */
.guidance-point + .guidance-point {
  border-top: 1px solid rgba(47, 138, 79, 0.14);
}

.guidance-point-chip {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  margin-top: 1px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(13, 21, 71, 0.06);
  color: var(--a-muted);
}

.guidance-point.is-warn .guidance-point-chip {
  background: rgba(205, 151, 43, 0.18);
  color: #8a6117;
}

.guidance-point.is-good .guidance-point-chip {
  background: rgba(47, 138, 79, 0.16);
  color: #1b6b41;
}

.guidance-point-copy {
  min-width: 0;
}

.guidance-point-label {
  display: block;
  margin-bottom: 3px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--a-ink);
}

.guidance-point.is-warn .guidance-point-label { color: #7a5512; }
.guidance-point.is-good .guidance-point-label { color: #1b6b41; }

/* Darker than the page's shared muted tone: this is the body copy of a
   recommendation, not a timestamp, and it was the lightest text on the
   card while carrying real instructions. */
.guidance-point-text {
  margin: 0;
  font-size: 12.5px;
  line-height: 1.55;
  color: #4d5b78;
  overflow-wrap: break-word;
}

.guidance-empty {
  margin: 0;
  padding: 16px;
  border-radius: 14px;
  background: #fff;
  border: 1px dashed rgba(47, 138, 79, 0.3);
  font-size: 12.5px;
  line-height: 1.6;
  color: #4d5b78;
}

/* ---- Empty / loading ---- */
.analytics-empty {
  display: grid;
  justify-items: center;
  align-content: center;
  gap: 6px;
  min-height: 150px;
  padding: 20px;
  text-align: center;
  border: 1px dashed rgba(13, 21, 71, 0.16);
  border-radius: var(--a-card-radius);
  background: rgba(255, 255, 255, 0.6);
  color: var(--a-muted);
}

.analytics-empty.is-inline {
  min-height: 110px;
}

.analytics-empty h3 {
  margin: 0;
  font-size: 13px;
  font-weight: 800;
  color: var(--a-ink);
}

.analytics-empty p {
  margin: 0;
  font-size: 12px;
  max-width: 34ch;
}

/* ---- Tooltip hint ---- */
.info-hint {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: rgba(16, 33, 73, 0.45);
  cursor: help;
  border-radius: 50%;
}

.info-hint:hover,
.info-hint:focus-visible {
  color: rgba(16, 33, 73, 0.78);
  outline: none;
}

.info-hint-bubble {
  position: absolute;
  z-index: 5;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%) translateY(4px);
  width: max-content;
  max-width: 250px;
  padding: 8px 10px;
  border-radius: 10px;
  background: var(--lnu-navy-deep, #102149);
  color: #ffffff;
  font-size: 12px;
  font-weight: 500;
  line-height: 1.4;
  text-align: left;
  text-transform: none;
  letter-spacing: 0;
  box-shadow: 0 10px 20px rgba(13, 21, 71, 0.22);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.12s ease, transform 0.12s ease;
}

.info-hint-bubble::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: var(--lnu-navy-deep, #102149);
}

.info-hint:hover .info-hint-bubble,
.info-hint:focus-visible .info-hint-bubble {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}

/* ---- Tone text ---- */
.ok { color: #226b3f; }
.danger-text { color: #c0392b; }
.gold-text { color: #8f6414; }
.navy-text { color: #234593; }
.muted-text { color: var(--a-muted); }

/* ---- Responsive ---- */
@media (max-width: 1200px) {
  .analytics-hero-row {
    grid-template-columns: minmax(0, 1fr);
  }

  /* Stacked, the tabs belong directly above the section they switch, so the
     order becomes brand panel, overall average, tabs. */
  .analytics-hero-col {
    display: contents;
  }

  .analytics-hero-card { order: 1; }
  .analytics-overview-card { order: 2; }
  .analytics-tabs { order: 3; }

  .subject-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 1000px) {
  .analytics-readiness-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 1080px) {
  .analytics-overview-body {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }

  .analytics-overview-chart {
    grid-column: 1 / -1;
    grid-row: 2;
  }
}

@media (max-width: 720px) {
  .subject-grid {
    grid-template-columns: 1fr;
  }

  .analytics-panel {
    padding: 16px 14px;
    border-radius: 18px;
  }

  .analytics-tab {
    height: 44px;
  }

  .analytics-overview-body {
    grid-template-columns: 1fr;
  }

  .analytics-overview-chart {
    grid-column: auto;
    grid-row: auto;
  }

  .analytics-overview-boxes {
    grid-template-columns: 1fr 1fr;
  }

  .analytics-card-head {
    flex-direction: column;
    align-items: flex-start;
  }

  .subject-card-meta {
    flex-direction: column;
    align-items: flex-start;
    gap: 5px;
  }

  .subject-card-trend {
    text-align: left;
  }

  /* The action drops to its own full-width row rather than squeezing four
     columns into a phone width -- the score stays on the title line, where
     it is the thing being scanned. */
  .plan-card {
    grid-template-columns: auto minmax(0, 1fr) auto;
    grid-template-areas:
      "rank copy score"
      "action action action";
    gap: 10px 12px;
  }

  .plan-review-btn,
  .plan-review-empty {
    justify-self: stretch;
    text-align: center;
  }

  .plan-review-empty {
    padding: 6px 0 0;
  }

  .analytics-guidance-card {
    padding: 15px 15px 16px;
  }

  .guidance-lead {
    padding: 13px 13px 14px 16px;
  }

  .guidance-point {
    padding: 11px 12px;
  }
}

/*
 * Without the sidebar an info icon sits within 130px of the screen's left
 * edge, and a 250px bubble centred on it ran off that edge. Here the bubble
 * hangs under the row its icon is in, starting at the row's left, and can
 * never be wider than the row.
 */
@media (max-width: 900px) {
  .analytics-overview-labelrow,
  .analytics-section-head {
    position: relative;
  }

  .info-hint {
    position: static;
  }

  .info-hint-bubble {
    top: calc(100% + 6px);
    bottom: auto;
    left: 0;
    max-width: min(100%, 320px);
    transform: translateY(-4px);
  }

  .info-hint-bubble::after {
    display: none;
  }

  .info-hint:hover .info-hint-bubble,
  .info-hint:focus-visible .info-hint-bubble {
    transform: translateY(0);
  }
}

@media (pointer: coarse) {
  /* A 12px icon: the padding is its tap area and the margin gives the room
     back, so nothing around it moves. */
  .info-hint {
    margin: -14px;
    padding: 14px;
  }

  /* A finger has no hover, and a tap does not count as keyboard focus. */
  .info-hint:focus .info-hint-bubble {
    opacity: 1;
  }

  .analytics-refresh-btn {
    width: 40px;
    height: 40px;
    margin-left: 6px;
    border-radius: 10px;
  }

  .analytics-refresh-btn svg {
    width: 16px;
    height: 16px;
  }

  .plan-review-btn {
    min-height: 44px;
  }
}

/* Three tabs across a phone: with their icons the labels do not all fit
   until the bar is about 370px wide. */
@media (max-width: 400px) {
  .analytics-tab {
    padding: 0 6px;
  }

  .analytics-tab svg {
    display: none;
  }
}

/* ---- Exam history ---- */
.exam-history {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  margin: 0;
  padding: 0;
  list-style: none;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-md);
  background: #ffffff;
}

.exam-history-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 16px;
}

.exam-history-row + .exam-history-row {
  border-top: 1px solid var(--border-soft);
}

/* An attempt a retake has replaced: still there, set back a little. */
.exam-history-row.is-earlier {
  background: var(--surface-muted);
}

.exam-history-copy {
  flex: 1 1 0;
  min-width: 0;
  display: grid;
  gap: 2px;
}

.exam-history-copy strong {
  font-size: 14px;
  color: var(--lnu-navy-deep);
  overflow-wrap: anywhere;
}

.exam-history-copy span {
  font-size: 12.5px;
  line-height: 1.4;
  color: var(--ink-soft);
  overflow-wrap: anywhere;
}

.exam-history-score {
  flex: none;
  display: grid;
  justify-items: end;
  gap: 4px;
}

.exam-history-score strong {
  font-size: 17px;
  font-variant-numeric: tabular-nums;
}

.exam-history-score .is-passing {
  color: #3949ab;
}

.exam-history-score .is-below {
  color: #a8700f;
}

.exam-history-score .is-ungraded {
  font-size: 13px;
  color: var(--ink-soft);
}

.exam-history-link {
  flex: none;
  text-decoration: none;
}

.exam-history-more {
  justify-self: center;
  margin-top: 12px;
}

@media (max-width: 640px) {
  .exam-history-row {
    flex-wrap: wrap;
    padding: 12px 14px;
  }

  .exam-history-copy {
    flex-basis: 100%;
  }

  .exam-history-score {
    flex: 1 1 auto;
    grid-auto-flow: column;
    justify-content: start;
    align-items: center;
    justify-items: start;
    gap: 8px;
  }
}
</style>
