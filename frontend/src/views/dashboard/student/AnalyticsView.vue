<template>
  <section class="dashboard-view analytics-page">
    <div v-if="analyticsError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ analyticsError }}</span>
    </div>

    <article class="surface-card analytics-header">
      <div>
        <p class="analytics-kicker">Analytics</p>
        <h3>Your progress at a glance</h3>
        <p>Simple view of your scores, subjects, and recent exams.</p>
      </div>

      <button type="button" class="ghost-btn analytics-refresh-btn" :disabled="analyticsLoading" @click="loadAnalytics">
        <RefreshCw :size="15" :class="{ 'spin-soft': analyticsLoading }" />
        Refresh
      </button>
    </article>

    <div class="stats-grid analytics-stats-grid">
      <article
        v-for="stat in decoratedStats"
        :key="stat.label"
        class="stat-card analytics-stat-card"
        :class="`analytics-stat-card-${stat.tone}`"
      >
        <div class="analytics-stat-top">
          <div class="stat-icon analytics-stat-icon" :class="`tone-${stat.tone}`">
            <component :is="stat.icon" :size="18" />
          </div>
          <span class="analytics-stat-tag" :class="`tone-${stat.tone}`">{{ stat.tag }}</span>
        </div>

        <div class="analytics-stat-label-row">
          <p class="stat-label analytics-stat-label">{{ stat.label }}</p>
          <span class="info-hint" tabindex="0" :aria-label="stat.description">
            <Info :size="13" />
            <span class="info-hint-bubble" role="tooltip">{{ stat.description }}</span>
          </span>
        </div>

        <div v-if="stat.showRadial" class="analytics-stat-radial-row">
          <RadialScore :value="averageScore" :size="92" />
          <p class="analytics-stat-note">{{ stat.hint }}</p>
        </div>
        <template v-else>
          <p class="stat-value analytics-stat-value">{{ stat.displayValue }}</p>
          <p class="analytics-stat-note">{{ stat.hint }}</p>
        </template>
      </article>
    </div>

    <div class="analytics-main-grid">
      <article class="surface-card analytics-card analytics-subjects-card">
        <header class="analytics-card-head">
          <div class="analytics-card-head-title">
            <h3>Subjects</h3>
            <span class="info-hint" tabindex="0" :aria-label="subjectsCardDescription">
              <Info :size="13" />
              <span class="info-hint-bubble" role="tooltip">{{ subjectsCardDescription }}</span>
            </span>
          </div>
          <span class="pill neutral">{{ subjects.length }}</span>
        </header>

        <div v-if="isInitialLoading" class="room-empty-state compact analytics-empty-state">
          <RefreshCw :size="26" class="spin-soft" />
          <h4>Loading subjects</h4>
          <p>Please wait.</p>
        </div>

        <div v-else class="subject-list">
          <article v-for="subject in subjects" :key="subject.label" class="subject-row">
            <div class="subject-row-top">
              <div>
                <strong>{{ subject.label }}</strong>
                <p>{{ subject.attempts_count }} {{ subject.attempts_count === 1 ? 'exam' : 'exams' }}</p>
              </div>

              <div class="subject-row-score">
                <span class="pill" :class="pillClass(subject.scoreTone)">{{ subject.bandLabel }}</span>
                <strong>{{ formatScore(subject.average_score_percent) }}</strong>
              </div>
            </div>

            <div v-if="subject.attempts_count === 0" class="subject-row-empty">
              No finished exam yet.
            </div>

            <template v-else>
              <div class="bar-track analytics-bar-track">
                <div class="bar-fill" :class="barClass(subject.scoreTone)" :style="{ width: `${subject.coveragePercent}%` }" />
              </div>

              <div class="subject-row-meta">
                <span>Last: {{ formatScore(subject.latest_score_percent) }}</span>
                <span>Best: {{ formatScore(subject.best_score_percent) }}</span>
                <span :class="textToneClass(subject.trendTone)">{{ trendLabel(subject) }}</span>
              </div>
            </template>
          </article>
        </div>
      </article>

      <div class="analytics-side-stack">
        <article class="surface-card analytics-card analytics-guidance-card">
          <header class="analytics-card-head">
            <div>
              <h3>What To Review Next</h3>
              <p>An in-depth read on your results, down to the specific topic if we have one mapped.</p>
            </div>
          </header>

          <div v-if="isInitialLoading" class="room-empty-state compact analytics-empty-state">
            <RefreshCw :size="26" class="spin-soft" />
            <h4>Loading notes</h4>
            <p>Please wait.</p>
          </div>

          <div v-else-if="reviewNarrative.empty" class="guidance-narrative">
            <Lightbulb :size="20" class="guidance-narrative-icon" />
            <p>{{ reviewNarrative.emptyMessage }}</p>
          </div>

          <div v-else class="guidance-narrative guidance-narrative-full">
            <div v-if="reviewNarrative.headline" class="guidance-headline">
              <span class="guidance-headline-icon"><Lightbulb :size="18" /></span>
              <div>
                <span class="guidance-kicker">{{ reviewNarrative.headline.label }}</span>
                <p class="guidance-headline-text">{{ reviewNarrative.headline.text }}</p>
              </div>
            </div>

            <ul v-if="reviewNarrative.points.length > 0" class="guidance-points">
              <li v-for="point in reviewNarrative.points" :key="point.label">
                <span class="guidance-point-dot" aria-hidden="true"></span>
                <span>
                  <strong class="guidance-point-label">{{ point.label }}:</strong>
                  <span class="guidance-point-text">{{ point.text }}</span>
                </span>
              </li>
            </ul>
          </div>
        </article>

        <article class="surface-card analytics-card analytics-history-card">
          <header class="analytics-card-head">
            <div>
              <div class="analytics-card-head-title">
                <h3>Recent Scores</h3>
                <span class="info-hint" tabindex="0" :aria-label="recentScoresDescription">
                  <Info :size="13" />
                  <span class="info-hint-bubble" role="tooltip">{{ recentScoresDescription }}</span>
                </span>
              </div>
              <p>See how your finished exam scores are moving over time.</p>
            </div>
            <span class="pill neutral">{{ scoreHistory.length }} exams</span>
          </header>

          <div v-if="!hasAttemptData && !analyticsLoading" class="room-empty-state compact analytics-empty-state">
            <ClipboardList :size="26" />
            <h4>No scores yet</h4>
            <p>Finish an exam first.</p>
          </div>

          <div v-else-if="scoreHistory.length === 0" class="room-empty-state compact analytics-empty-state">
            <ClipboardList :size="26" />
            <h4>No scores yet</h4>
            <p>Finish an exam first.</p>
          </div>

          <ScoreTrendChart v-else :points="trendPoints" />
        </article>

        <article class="surface-card analytics-card predictive-card analytics-prediction-card">
          <header class="analytics-card-head">
            <div>
              <h3>LET Readiness Overview</h3>
              <p>{{ predictivePanel.description }}</p>
            </div>
            <span class="pill" :class="predictivePanel.isConnected ? predictivePanel.predictionTone : 'gold'">
              {{ predictivePanel.statusLabel }}
            </span>
          </header>

          <div v-if="predictivePanel.isConnected" class="predictive-connected">
            <div class="predictive-status-row">
              <div class="predictive-icon-wrap" :class="`tone-${predictivePanel.predictionTone}`">
                <ShieldCheck v-if="predictivePanel.prediction === 'PASS'" :size="20" />
                <AlertCircle v-else :size="20" />
              </div>

              <div>
                <strong>
                  {{ predictivePanel.prediction === 'PASS' ? 'Likely to pass the LET' : 'At risk of not passing the LET' }}
                </strong>
                <p>Estimated confidence: {{ predictivePanel.probability_percent }}% (based on {{ predictivePanel.support }} similar past cases)</p>
              </div>
            </div>

            <details class="predictive-reasoning">
              <summary>Why this estimate?</summary>
              <ul class="predictive-rule-list">
                <li v-for="(rule, index) in predictivePanel.decision_rules" :key="index">{{ rule }}</li>
              </ul>
            </details>

            <p class="predictive-note">
              This model was trained on a very small sample of past students, so treat it as a rough signal —
              not a certainty. Use your subject scores and recent results above to decide what to focus on.
            </p>
          </div>

          <div v-else-if="predictivePanel.isInsufficientHistory" class="predictive-placeholder">
            <div class="predictive-status-row">
              <div class="predictive-icon-wrap">
                <Sparkles :size="20" />
              </div>

              <div>
                <strong>Not enough exam history yet</strong>
                <p>{{ predictivePanel.description }}</p>
              </div>
            </div>

            <p class="predictive-note">
              For now, use your subject scores and recent results above to identify what to focus on and improve before the LET.
            </p>
          </div>

          <div v-else class="predictive-placeholder">
            <div class="predictive-status-row">
              <div class="predictive-icon-wrap">
                <Sparkles :size="20" />
              </div>

              <div>
                <strong>Skill gap analysis is not connected yet</strong>
                <p>Once connected, this will highlight the subject areas you need to strengthen before your LET exam.</p>
              </div>
            </div>

            <p class="predictive-note">
              For now, use your subject scores and recent results above to identify what to focus on and improve before the LET.
            </p>
          </div>
        </article>
      </div>
    </div>

    <article class="surface-card analytics-card analytics-results-card">
      <header class="analytics-card-head">
        <h3>Recent Exams</h3>
        <span class="pill neutral">{{ recentAttempts.length }}</span>
      </header>

      <div v-if="!hasAttemptData && !analyticsLoading" class="room-empty-state compact analytics-empty-state">
        <BarChart3 :size="26" />
        <h4>No results yet</h4>
        <p>Your finished exams will appear here.</p>
      </div>

      <div v-else-if="recentAttempts.length === 0" class="room-empty-state compact analytics-empty-state">
        <BarChart3 :size="26" />
        <h4>No results yet</h4>
        <p>Your finished exams will appear here.</p>
      </div>

      <div v-else class="results-list">
        <article v-for="attempt in recentAttempts" :key="attempt.attempt_id" class="result-row">
          <div class="result-row-main">
            <strong>{{ attempt.title }}</strong>
            <p>{{ attempt.subject || 'Uncategorized' }}</p>
          </div>

          <span class="pill" :class="pillClass(attempt.scoreTone)">{{ attempt.scoreDisplay }}</span>

          <div class="result-row-meta">
            <span>{{ attempt.correct_answers }}/{{ attempt.total_items }} correct</span>
            <span v-if="attempt.room?.name">{{ attempt.room.name }}</span>
            <span>{{ attempt.submittedAtText }}</span>
          </div>
        </article>
      </div>
    </article>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import {
  AlertCircle,
  BarChart3,
  ClipboardList,
  Gauge,
  Info,
  Lightbulb,
  RefreshCw,
  ShieldCheck,
  Sparkles,
} from 'lucide-vue-next'
import { useStudentInsightsModule } from '../composables/useStudentInsightsModule'
import ScoreTrendChart from '@/components/charts/ScoreTrendChart.vue'
import RadialScore from '@/components/charts/RadialScore.vue'

const {
  analyticsLoading,
  analyticsLoaded,
  analyticsError,
  hasAttemptData,
  statCards,
  subjects,
  averageScore,
  reviewNarrative,
  scoreHistory,
  recentAttempts,
  predictivePanel,
  loadAnalytics,
} = useStudentInsightsModule()

const subjectsCardDescription = 'The big percentage is your average score across every finished exam in this subject. '
  + 'The bar plots it against the 75% passing mark (gold line). '
  + 'Last = your most recent score, Best = your highest score, trend = change vs. your previous attempt in this subject.'

const recentScoresDescription = 'A1 through A8 are your last 8 finished exams in order, oldest (A1) to most recent (A8) -- '
  + 'not tied to any one subject. Hover a point on the chart to see which exam it was and its exact score.'

const statMeta = [
  {
    icon: ClipboardList,
    tag: 'Progress',
    description: 'How many exams you have fully submitted. Exams you started but haven\'t finished yet aren\'t counted.',
  },
  {
    icon: Gauge,
    tag: 'Average',
    showRadial: true,
    description: 'Your average score across every exam you have completed so far.',
  },
]

const decoratedStats = computed(() => (
  statCards.value.map((stat, index) => ({
    ...stat,
    icon: statMeta[index]?.icon ?? BarChart3,
    tag: statMeta[index]?.tag ?? 'Summary',
    description: statMeta[index]?.description ?? '',
    displayValue: stat.value === 'N/A' ? '--' : stat.value,
    showRadial: Boolean(statMeta[index]?.showRadial) && averageScore.value !== null,
  }))
))

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
function formatScore(value) {
  return value === null || value === undefined ? '--' : `${Number(value).toFixed(1)}%`
}

function trendLabel(subject) {
  if (subject.trend === 'up') return `Improving${subject.trend_delta ? ` +${Math.abs(subject.trend_delta).toFixed(1)}` : ''}`
  if (subject.trend === 'down') return `Went down${subject.trend_delta ? ` -${Math.abs(subject.trend_delta).toFixed(1)}` : ''}`
  if (subject.trend === 'steady') return 'Steady'
  return 'No data'
}

function pillClass(tone) {
  if (tone === 'success') return 'success'
  if (tone === 'navy') return 'navy'
  if (tone === 'gold') return 'gold'
  if (tone === 'danger') return 'danger'
  return 'neutral'
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

</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.analytics-page {
  gap: 16px;
}

.analytics-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  background: #20428f;
  border-color: rgba(24, 55, 124, 0.34);
  color: #ffffff;
}

.analytics-kicker {
  margin: 0 0 6px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgba(255, 230, 171, 0.92);
}

.analytics-header h3 {
  margin: 0 0 6px;
  color: #ffffff;
  font-size: clamp(24px, 3vw, 30px);
}

.analytics-header p:last-child {
  margin: 0;
  color: rgba(241, 246, 255, 0.88);
}

.analytics-refresh-btn {
  border-color: rgba(255, 255, 255, 0.22);
  background: rgba(255, 255, 255, 0.12);
  color: #ffffff;
}

.analytics-refresh-btn:hover:not(:disabled) {
  border-color: rgba(255, 255, 255, 0.32);
  background: rgba(255, 255, 255, 0.18);
}

.analytics-stats-grid {
  /* Capped and left-stuck (no auto margin) left a large empty gap next to
     the two remaining tiles on anything wider than a phone -- matches the
     full-width rhythm every other section on this page already uses. */
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.analytics-stat-card {
  min-height: 156px;
  display: grid;
  gap: 10px;
  align-content: start;
  padding: 16px;
  border-width: 1px;
}

.analytics-stat-card-navy {
  background: #dfe9ff;
  border-color: rgba(49, 92, 191, 0.22);
}

.analytics-stat-card-success {
  background: #ddf3e5;
  border-color: rgba(63, 146, 94, 0.22);
}

.analytics-stat-card-gold {
  background: #ffefc4;
  border-color: rgba(205, 151, 43, 0.22);
}

.analytics-stat-card-danger {
  background: #ffe2de;
  border-color: rgba(198, 40, 40, 0.18);
}

.analytics-stat-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.analytics-stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 14px;
}

.analytics-stat-tag {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 5px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.analytics-stat-tag.tone-navy,
.analytics-stat-icon.tone-navy {
  background: rgba(33, 72, 154, 0.14);
  color: #21489a;
}

.analytics-stat-tag.tone-success,
.analytics-stat-icon.tone-success {
  background: rgba(47, 138, 79, 0.14);
  color: #267242;
}

.analytics-stat-tag.tone-gold,
.analytics-stat-icon.tone-gold {
  background: rgba(207, 138, 31, 0.14);
  color: #9d6110;
}

.analytics-stat-tag.tone-danger,
.analytics-stat-icon.tone-danger {
  background: rgba(181, 54, 54, 0.12);
  color: #9f3434;
}

.analytics-stat-label-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.analytics-stat-label {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: rgba(16, 33, 73, 0.72);
}

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
  color: rgba(16, 33, 73, 0.75);
  outline: none;
}

.info-hint-bubble {
  position: absolute;
  z-index: 5;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%) translateY(4px);
  width: max-content;
  max-width: 240px;
  padding: 8px 10px;
  border-radius: 10px;
  background: var(--lnu-navy-deep, #102149);
  color: #ffffff;
  font-size: 12px;
  font-weight: 500;
  line-height: 1.4;
  text-align: left;
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

.analytics-stat-value {
  margin: 0;
  color: #102149;
  font-size: clamp(34px, 3vw, 40px);
  line-height: 1;
}

.analytics-stat-note {
  margin: 0;
  color: rgba(16, 33, 73, 0.72);
  font-size: 13px;
  line-height: 1.45;
}

.analytics-main-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(300px, 0.8fr);
  gap: 16px;
}

.analytics-side-stack {
  display: grid;
  gap: 16px;
}

.analytics-card {
  display: grid;
  gap: 14px;
  min-height: 0;
}

.analytics-subjects-card,
.analytics-results-card {
  background: #ffffff;
}

.analytics-guidance-card {
  background:
    radial-gradient(circle at 88% 18%, rgba(121, 203, 145, 0.22), transparent 24%),
    linear-gradient(160deg, #def6e4 0%, #eefaf1 42%, #ffffff 100%);
  border-color: rgba(63, 146, 94, 0.2);
}

.analytics-history-card {
  background:
    radial-gradient(circle at 88% 16%, rgba(94, 191, 223, 0.2), transparent 24%),
    linear-gradient(160deg, #def3fb 0%, #eef9fd 42%, #ffffff 100%);
  border-color: rgba(49, 92, 191, 0.18);
}

.analytics-prediction-card {
  background:
    radial-gradient(circle at 88% 16%, rgba(245, 196, 89, 0.26), transparent 22%),
    linear-gradient(160deg, #ffe7b4 0%, #fff4d8 42%, #ffffff 100%);
  border-color: rgba(205, 151, 43, 0.2);
}

.analytics-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.analytics-card-head-title {
  display: flex;
  align-items: center;
  gap: 6px;
}

.analytics-card-head h3 {
  margin: 0;
  color: var(--lnu-navy-deep);
}

.analytics-card-head p {
  margin: 4px 0 0;
  color: rgba(17, 33, 73, 0.68);
  font-size: 14px;
}

.analytics-empty-state {
  min-height: 180px;
}

.subject-list,
.results-list {
  display: grid;
  gap: 12px;
}

.subject-row,
.result-row,
.score-chip {
  border: 1px solid rgba(13, 21, 71, 0.1);
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.94);
}

.subject-row {
  display: grid;
  gap: 10px;
  padding: 14px;
}

.subject-row-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.subject-row-top strong,
.result-row-main strong {
  display: block;
  color: var(--lnu-text);
}

.subject-row-top p,
.result-row-main p {
  margin: 4px 0 0;
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.subject-row-score {
  display: grid;
  justify-items: end;
  gap: 8px;
}

.subject-row-score strong {
  font-size: 18px;
  color: var(--lnu-navy-deep);
}

.subject-row-empty {
  padding: 12px;
  border-radius: 12px;
  background: #f6f8fd;
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.analytics-bar-track {
  position: relative;
  overflow: visible;
  height: 12px;
  background: rgba(31, 65, 143, 0.12);
  background-image: repeating-linear-gradient(
    to right,
    rgba(13, 21, 71, 0.28) 0,
    rgba(13, 21, 71, 0.28) 2px,
    transparent 2px,
    transparent 25%
  );
}

.analytics-bar-track::after {
  content: '';
  position: absolute;
  top: -5px;
  bottom: -5px;
  left: 75%;
  width: 3px;
  background: #cd972b;
  border-radius: 2px;
  box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.6);
}

.bar-fill.navy {
  background: #315cbf;
}

.bar-fill.ok {
  background: #3f925e;
}

.bar-fill.gold {
  background: #cd972b;
}

.bar-fill.danger {
  background: #c24b4b;
}

.subject-row-meta,
.result-row-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  color: var(--lnu-text-muted);
  font-size: 12px;
}

.guidance-narrative {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px;
  border: 1px solid rgba(42, 123, 76, 0.24);
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(235, 248, 239, 0.98));
  box-shadow: 0 10px 18px rgba(43, 124, 76, 0.08);
}

.guidance-narrative-full {
  display: grid;
  gap: 16px;
}

.guidance-narrative-icon {
  flex-shrink: 0;
  color: #2a7b4c;
}

.guidance-narrative > p {
  margin: 0;
  color: var(--lnu-text);
  font-size: 14px;
  line-height: 1.6;
}

.guidance-headline {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.guidance-headline-icon {
  flex-shrink: 0;
  width: 34px;
  height: 34px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(42, 123, 76, 0.14);
  color: #2a7b4c;
}

.guidance-kicker {
  display: block;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #2a7b4c;
  margin-bottom: 4px;
}

.guidance-headline-text {
  margin: 0;
  color: var(--lnu-navy-deep);
  font-size: 16px;
  font-weight: 600;
  line-height: 1.5;
}

.guidance-points {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 10px;
}

.guidance-points li {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}

.guidance-point-dot {
  flex-shrink: 0;
  width: 7px;
  height: 7px;
  margin-top: 7px;
  border-radius: 50%;
  background: #2a7b4c;
}

.guidance-point-label {
  color: var(--lnu-navy-deep);
  font-size: 13px;
  font-weight: 700;
  margin-right: 4px;
}

.guidance-point-text {
  color: var(--lnu-text-muted);
  font-size: 13px;
  line-height: 1.5;
}

.predictive-card {
  overflow: hidden;
}

.predictive-placeholder {
  border: 1px dashed rgba(181, 126, 24, 0.32);
  border-radius: 16px;
  padding: 16px;
  background:
    radial-gradient(circle at top right, rgba(245, 196, 89, 0.32), transparent 34%),
    linear-gradient(180deg, rgba(255, 249, 231, 0.98), rgba(255, 255, 255, 0.96));
  display: grid;
  gap: 14px;
}

.predictive-status-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.predictive-icon-wrap {
  width: 42px;
  height: 42px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(212, 145, 24, 0.2), rgba(255, 215, 131, 0.34));
  color: #8a6117;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.predictive-status-row strong {
  display: block;
  margin-bottom: 4px;
  color: var(--lnu-text);
}

.predictive-status-row p,
.predictive-note {
  margin: 0;
  font-size: 14px;
  color: var(--lnu-text-muted);
  line-height: 1.5;
}

.analytics-stat-radial-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.analytics-stat-radial-row .analytics-stat-note {
  margin: 0;
}

.result-row {
  display: grid;
  gap: 10px;
  padding: 14px;
}

.pill.gold {
  background: rgba(207, 138, 31, 0.16);
  color: #8c5610;
}

.pill.danger {
  background: rgba(198, 40, 40, 0.14);
  color: #a43131;
}

.pill.success {
  background: rgba(47, 138, 79, 0.16);
  color: #256d3f;
}

.pill.navy {
  background: rgba(33, 72, 154, 0.14);
  color: #234593;
}

.pill.neutral {
  background: rgba(21, 127, 166, 0.12);
  color: #155e78;
}

.ok {
  color: #267242;
}

.danger-text {
  color: #a43131;
}

.gold-text {
  color: #9a6a16;
}

.navy-text {
  color: #234593;
}

.muted-text {
  color: var(--lnu-text-muted);
}

@media (max-width: 1200px) {
  .analytics-main-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .analytics-header,
  .subject-row-top,
  .analytics-card-head {
    flex-direction: column;
    align-items: flex-start;
  }

  .analytics-stats-grid {
    grid-template-columns: 1fr;
  }

  .subject-row-score {
    justify-items: start;
  }
}
</style>
