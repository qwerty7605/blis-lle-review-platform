<template>
  <section class="dashboard-view student-home">
    <div v-if="dashboardError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ dashboardError }}</span>
    </div>

    <header class="home-header">
      <div class="home-greeting">
        <p class="home-kicker">Home</p>
        <h2>{{ studentFirstName }}, here's your overview.</h2>
        <p class="home-context">{{ contextLine }}</p>
      </div>
      <button
        class="ghost-btn home-refresh-btn"
        :disabled="dashboardLoading"
        @click="loadDashboard"
      >
        <RefreshCw :size="14" :class="{ 'spin-soft': dashboardLoading }" />
        Refresh
      </button>
    </header>

    <div class="home-stat-row">
      <div
        v-for="stat in quickStats"
        :key="stat.label"
        class="home-stat"
        :class="`tone-${stat.tone}`"
      >
        <RadialScore v-if="stat.isScoreRadial" :value="stat.radialValue" :size="58" />
        <component :is="stat.icon" v-else :size="17" class="home-stat-icon" />
        <div>
          <strong class="home-stat-value">{{ stat.value }}</strong>
          <p class="home-stat-label">{{ stat.label }}</p>
        </div>
      </div>
    </div>

    <div class="home-content-grid">
      <article class="surface-card home-block">
        <div class="home-block-head">
          <div class="home-block-title">
            <BookOpen :size="16" />
            <h3>What to Review Next</h3>
          </div>
          <p class="home-block-sub">Subjects ranked by lowest average score — focus here first.</p>
        </div>

        <div v-if="isInitialLoading" class="home-state-box">
          <RefreshCw :size="20" class="spin-soft" />
          <p>Loading suggestions...</p>
        </div>

        <div v-else-if="reviewSuggestions.length === 0" class="home-state-box">
          <BookOpen :size="26" />
          <strong>Nothing to suggest yet</strong>
          <p>Take an exam and your focus areas will appear here.</p>
        </div>

        <ul v-else class="review-list">
          <li
            v-for="(item, i) in reviewSuggestions"
            :key="item.label"
            class="review-item"
          >
            <span class="review-rank">{{ i + 1 }}</span>
            <div class="review-item-main">
              <div class="review-item-top">
                <strong class="review-item-label">{{ item.label }}</strong>
                <span class="review-item-score" :class="`text-${item.tone}`">{{ item.scoreDisplay }}</span>
              </div>
              <div class="review-bar-track">
                <div
                  class="review-bar-fill"
                  :class="`fill-${item.tone}`"
                  :style="{ width: `${item.pct}%` }"
                />
              </div>
              <p class="review-item-hint">{{ item.hint }}</p>
            </div>
          </li>
        </ul>
      </article>

      <article class="surface-card home-block notices-block">
        <div class="home-block-head">
          <div class="home-block-title">
            <Bell :size="16" />
            <h3>Exam Notices</h3>
            <span v-if="notifications.length > 0" class="notices-badge">{{ notifications.length }}</span>
          </div>
          <p class="home-block-sub">Open, resumable, and upcoming exams assigned to you.</p>
        </div>

        <div v-if="isInitialLoading" class="home-state-box">
          <RefreshCw :size="20" class="spin-soft" />
          <p>Checking notices...</p>
        </div>

        <div v-else-if="notifications.length === 0" class="home-state-box">
          <BellRing :size="26" />
          <strong>No exam notices</strong>
          <p>Available or upcoming exams will show here.</p>
        </div>

        <ul v-else class="notice-list">
          <li
            v-for="notice in notifications"
            :key="notice.id"
            class="notice-item"
            :class="`is-${notice.tone}`"
          >
            <span class="notice-dot" :class="`dot-${notice.tone}`" />
            <div class="notice-item-body">
              <strong>{{ notice.title }}</strong>
              <p>{{ notice.roomName }}</p>
            </div>
            <span class="notice-tag" :class="`tag-${notice.tone}`">{{ notice.badge }}</span>
          </li>
        </ul>
      </article>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import {
  AlertCircle,
  BarChart3,
  Bell,
  BellRing,
  BookOpen,
  ClipboardCheck,
  Clock3,
  Play,
  RefreshCw,
} from 'lucide-vue-next'
import { useStudentDashboardModule } from '../composables/useStudentDashboardModule'
import RadialScore from '@/components/charts/RadialScore.vue'

const {
  studentFirstName,
  dashboardLoading,
  dashboardLoaded,
  dashboardError,
  statsCards,
  averageScore,
  notifications,
  recentExamCards,
  loadDashboard,
} = useStudentDashboardModule()

const isInitialLoading = computed(() => dashboardLoading.value && !dashboardLoaded.value)

const examsAvailable = computed(() =>
  notifications.value.filter(n => ['in_progress', 'available', 'retake'].includes(n.state)).length
)

const examsUpcoming = computed(() =>
  notifications.value.filter(n => n.state === 'upcoming').length
)

const quickStats = computed(() => [
  {
    label: 'Exams Taken',
    value: statsCards.value[0]?.value ?? '0',
    icon: ClipboardCheck,
    tone: 'navy',
  },
  {
    label: 'Available Now',
    value: examsAvailable.value,
    icon: Play,
    tone: examsAvailable.value > 0 ? 'success' : 'neutral',
  },
  {
    label: 'Upcoming',
    value: examsUpcoming.value,
    icon: Clock3,
    tone: examsUpcoming.value > 0 ? 'gold' : 'neutral',
  },
  {
    label: 'Avg Score',
    value: statsCards.value[2]?.value ?? 'N/A',
    icon: BarChart3,
    tone: 'navy',
    isScoreRadial: averageScore.value !== null,
    radialValue: averageScore.value,
  },
])

const contextLine = computed(() => {
  if (examsAvailable.value > 0) {
    return `You have ${examsAvailable.value} exam${examsAvailable.value === 1 ? '' : 's'} ready to take.`
  }
  if (examsUpcoming.value > 0) {
    return `${examsUpcoming.value} exam${examsUpcoming.value === 1 ? ' is' : 's are'} coming up soon.`
  }
  if (recentExamCards.value.length > 0) {
    return 'Review your focus areas below and keep improving before the LET.'
  }
  return 'Join a room and take exams to start tracking your LET readiness.'
})

const reviewSuggestions = computed(() => {
  const cards = recentExamCards.value
  if (cards.length === 0) return []

  const groups = {}
  for (const card of cards) {
    const key = card.subject || card.title || 'General'
    if (!groups[key]) groups[key] = { scores: [], label: key }
    groups[key].scores.push(Number(card.score_percent ?? 0))
  }

  return Object.values(groups)
    .map(({ label, scores }) => {
      const avg = scores.reduce((s, n) => s + n, 0) / scores.length
      const pct = Math.min(100, Math.max(0, Math.round(avg)))
      const tone = avg >= 75 ? 'success' : avg >= 50 ? 'gold' : 'danger'
      return {
        label,
        pct,
        scoreDisplay: `${avg.toFixed(1)}%`,
        hint: scores.length === 1 ? '1 attempt' : `${scores.length} attempts averaged`,
        tone,
      }
    })
    .sort((a, b) => a.pct - b.pct)
    .slice(0, 5)
})
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.student-home {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* Header */
.home-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  background: linear-gradient(135deg, #1a3a8c 0%, #2651b8 100%);
  border-radius: 18px;
  padding: 20px 24px;
}

.home-kicker {
  margin: 0 0 4px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.13em;
  text-transform: uppercase;
  color: #f2d27a;
}

.home-header h2 {
  margin: 0;
  font-size: 22px;
  color: #ffffff;
}

.home-context {
  margin: 6px 0 0;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.8);
}

.home-refresh-btn {
  flex-shrink: 0;
  margin-top: 2px;
  border-color: rgba(255, 232, 177, 0.38);
  background: rgba(255, 255, 255, 0.1);
  color: #fff4d6;
}

.home-refresh-btn:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.2);
  color: #ffffff;
}

/* Stat row */
.home-stat-row {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}

.home-stat {
  display: flex;
  align-items: center;
  gap: 12px;
  border-radius: 14px;
  padding: 14px 16px;
  border: 1px solid rgba(26, 35, 126, 0.1);
  background: #ffffff;
  box-shadow: 0 2px 6px rgba(20, 39, 88, 0.06);
}

.home-stat.tone-navy { background: #edf2ff; border-color: rgba(58, 101, 198, 0.2); }
.home-stat.tone-success { background: #eaf6ef; border-color: rgba(46, 125, 50, 0.2); }
.home-stat.tone-gold { background: #fff8e6; border-color: rgba(201, 168, 76, 0.22); }
.home-stat.tone-neutral { background: #f4f6fa; border-color: rgba(26, 35, 126, 0.08); }
.home-stat.tone-danger { background: #fff0f0; border-color: rgba(180, 40, 40, 0.18); }

.home-stat-icon {
  flex-shrink: 0;
  opacity: 0.65;
  color: var(--lnu-navy);
}

.home-stat.tone-success .home-stat-icon { color: #2e7d32; }
.home-stat.tone-gold .home-stat-icon { color: #b87e12; }
.home-stat.tone-danger .home-stat-icon { color: #a13232; }

.home-stat-value {
  display: block;
  font-size: 22px;
  line-height: 1.1;
  color: var(--lnu-text);
}

.home-stat-label {
  margin: 3px 0 0;
  font-size: 11px;
  color: var(--lnu-text-muted);
}

/* Content grid */
.home-content-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) minmax(280px, 1fr);
  gap: 14px;
}

.home-block {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 18px 20px;
}

.home-block-head {
  display: grid;
  gap: 4px;
}

.home-block-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.home-block-title h3 {
  margin: 0;
  font-size: 16px;
  color: var(--lnu-text);
}

.home-block-sub {
  margin: 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
  line-height: 1.45;
}

.notices-badge {
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background: #c0392b;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

/* Loading / empty */
.home-state-box {
  display: grid;
  place-items: center;
  text-align: center;
  gap: 8px;
  padding: 28px 16px;
  border: 1px dashed rgba(26, 35, 126, 0.14);
  border-radius: 12px;
  background: rgba(248, 249, 252, 0.8);
  color: var(--lnu-text-muted);
}

.home-state-box strong {
  color: var(--lnu-text);
  font-size: 14px;
}

.home-state-box p {
  margin: 0;
  max-width: 240px;
  font-size: 13px;
  line-height: 1.5;
}

/* Review list */
.review-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 10px;
}

.review-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 12px;
  background: #f8f9fc;
  border: 1px solid rgba(26, 35, 126, 0.07);
}

.review-rank {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: rgba(26, 35, 126, 0.1);
  color: var(--lnu-navy);
  font-size: 12px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 2px;
}

.review-item-main {
  flex: 1;
  min-width: 0;
  display: grid;
  gap: 6px;
}

.review-item-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.review-item-label {
  font-size: 14px;
  color: var(--lnu-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.review-item-score {
  font-size: 14px;
  font-weight: 700;
  flex-shrink: 0;
}

.text-success { color: #2e7d32; }
.text-gold { color: #b87e12; }
.text-danger { color: #c0392b; }

.review-bar-track {
  position: relative;
  height: 8px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.1);
  background-image: repeating-linear-gradient(
    to right,
    rgba(26, 35, 126, 0.3) 0,
    rgba(26, 35, 126, 0.3) 2px,
    transparent 2px,
    transparent 25%
  );
  overflow: visible;
}

.review-bar-track::after {
  content: '';
  position: absolute;
  top: -4px;
  bottom: -4px;
  left: 75%;
  width: 3px;
  background: #c9961e;
  border-radius: 2px;
  box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.6);
}

.review-bar-fill {
  height: 100%;
  border-radius: 999px;
  transition: width 0.35s ease;
}

.fill-success { background: #43a255; }
.fill-gold { background: #c9961e; }
.fill-danger { background: #c0392b; }

.review-item-hint {
  margin: 0;
  font-size: 11px;
  color: var(--lnu-text-muted);
}

/* Notice list */
.notices-block {
  background: #fcfcff;
  border-color: rgba(57, 102, 199, 0.12);
}

.notice-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
}

.notice-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid transparent;
  background: #f4f6fa;
}

.notice-item.is-success { background: #f0faf3; border-color: rgba(46, 125, 50, 0.18); }
.notice-item.is-navy { background: #eff4ff; border-color: rgba(57, 102, 199, 0.18); }
.notice-item.is-gold { background: #fffbf0; border-color: rgba(201, 168, 76, 0.22); }
.notice-item.is-neutral { background: #f4f6fa; border-color: rgba(26, 35, 126, 0.08); }
.notice-item.is-danger { background: #fff5f5; border-color: rgba(192, 57, 43, 0.18); }

.notice-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.dot-success { background: #2e7d32; }
.dot-navy { background: #1e4699; }
.dot-gold { background: #b87e12; }
.dot-neutral { background: #8896aa; }
.dot-danger { background: #c0392b; }

.notice-item-body {
  flex: 1;
  min-width: 0;
}

.notice-item-body strong {
  display: block;
  font-size: 13px;
  color: var(--lnu-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notice-item-body p {
  margin: 2px 0 0;
  font-size: 11px;
  color: var(--lnu-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notice-tag {
  font-size: 11px;
  font-weight: 700;
  padding: 3px 8px;
  border-radius: 999px;
  flex-shrink: 0;
}

.tag-success { background: #ddf3e5; color: #22683e; }
.tag-navy { background: #dbe6ff; color: #183b89; }
.tag-gold { background: #ffe8b0; color: #7a5312; }
.tag-neutral { background: #e6eefc; color: #385496; }
.tag-danger { background: #fde0e0; color: #a13232; }

/* Responsive */
@media (max-width: 1100px) {
  .home-content-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 860px) {
  .home-stat-row {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 600px) {
  .home-stat-row {
    grid-template-columns: 1fr 1fr;
  }

  .home-header {
    flex-direction: column;
    gap: 10px;
  }
}
</style>
