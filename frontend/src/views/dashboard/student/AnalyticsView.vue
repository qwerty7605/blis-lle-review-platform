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

        <!-- Topics -->
        <section v-show="activeTab === 'topics'" class="analytics-section" aria-labelledby="analytics-topics-heading">
          <header class="analytics-section-head">
            <h2 id="analytics-topics-heading" class="analytics-section-title">Topics</h2>
            <InfoTip :text="topicsDescription" label="About topic mastery" />
          </header>

          <div v-if="isInitialLoading" class="analytics-empty">
            <RefreshCw :size="22" class="spin-soft" />
            <p>Loading your topics...</p>
          </div>

          <TopicMastery v-else :subjects="mastery" />
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
                  :aria-label="`Estimated grasp ${reviewNarrative.headline.mastery.percent} percent, based on ${reviewNarrative.headline.mastery.attempted} questions in ${reviewNarrative.headline.mastery.exams} exams`"
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
                  <span>from {{ reviewNarrative.headline.mastery.attempted }} {{ reviewNarrative.headline.mastery.attempted === 1 ? 'question' : 'questions' }} in {{ reviewNarrative.headline.mastery.exams }} {{ reviewNarrative.headline.mastery.exams === 1 ? 'exam' : 'exams' }}</span>
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
import TopicMastery from '@/components/TopicMastery.vue'

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
  mastery,
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
  'Reference, Bibliography and User Services': BookOpen,
  'Library Organization and Management': Building2,
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

// The sections of the page, shown one at a time.

const topicsDescription = 'Each subject shows the share of its questions you got right. Under it, '
  + 'each topic of the Table of Specifications shows how well you seem to know it: '
  + 'an estimate that moves once per exam, in the order you took them, '
  + 'so a topic you are climbing into reads differently from one you are slipping on.'
const tabs = [
  { key: 'subjects', label: 'Subjects', icon: LayoutGrid },
  { key: 'topics', label: 'Topics', icon: ListTree },
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

<style scoped src="./analyticsView.css"></style>
