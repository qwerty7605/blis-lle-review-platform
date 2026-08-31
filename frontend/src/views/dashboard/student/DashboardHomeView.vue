<template>
  <section class="dashboard-view student-home">
    <div v-if="dashboardError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ dashboardError }}</span>
    </div>

    <header class="home-page-head">
      <h2>Welcome, {{ studentFirstName }}</h2>
      <button
        class="ghost-btn home-refresh-btn"
        :disabled="dashboardLoading"
        @click="loadDashboard"
      >
        <RefreshCw :size="14" :class="{ 'spin-soft': dashboardLoading }" />
        Refresh
      </button>
    </header>

    <div class="home-main-grid">
      <!-- 1. Notifications: filterable, scrollable, clickable to the room -->
      <article class="surface-card home-block notices-block">
        <div class="home-block-head">
          <div class="home-block-title">
            <Bell :size="17" />
            <h3>Notification</h3>
          </div>

          <div class="notice-filter-tabs" role="tablist">
            <button
              v-for="tab in filterTabs"
              :key="tab.key"
              type="button"
              class="notice-filter-tab"
              :class="{ active: activeFilter === tab.key }"
              role="tab"
              :aria-selected="activeFilter === tab.key"
              @click="activeFilter = tab.key"
            >
              {{ tab.label }}
              <span v-if="tab.count > 0" class="notice-filter-count">{{ tab.count }}</span>
            </button>
          </div>
        </div>

        <div v-if="isInitialLoading" class="home-state-box">
          <RefreshCw :size="20" class="spin-soft" />
          <p>Checking notices...</p>
        </div>

        <div v-else-if="filteredNotifications.length === 0" class="home-state-box">
          <BellRing :size="26" />
          <strong>{{ notifications.length === 0 ? 'No exam notices' : 'Nothing in this filter' }}</strong>
          <p>{{ notifications.length === 0 ? 'Available or upcoming exams will show here.' : 'Try a different tab above.' }}</p>
        </div>

        <ul v-else class="notice-list">
          <li
            v-for="notice in filteredNotifications"
            :key="notice.id"
            class="notice-item"
            role="button"
            tabindex="0"
            @click="goToNotice(notice)"
            @keyup.enter="goToNotice(notice)"
          >
            <span class="notice-icon">
              <component :is="noticeIcon(notice.state)" :size="17" />
            </span>
            <div class="notice-item-body">
              <strong>{{ notice.title }}</strong>
              <div class="notice-item-meta">
                <span class="notice-tag" :class="`tag-${notice.category}`">{{ notice.categoryLabel }}</span>
                <span v-if="notice.whenLabel" class="notice-meta-text">{{ notice.whenLabel }}: {{ notice.whenText }}</span>
                <span class="notice-meta-text notice-meta-room">{{ notice.roomName }}</span>
              </div>
            </div>
          </li>
        </ul>
      </article>

      <!-- 2 & 3: estimated outcome and last-result, stacked in one column -->
      <div class="home-side-col">
        <article class="surface-card outcome-card">
          <div class="home-block-head">
            <div class="home-block-title">
              <h3>Estimated outcome</h3>
              <Info :size="14" class="info-hint" :title="readiness.detail" />
            </div>
          </div>

          <div v-if="isInitialLoading" class="home-state-box">
            <RefreshCw :size="20" class="spin-soft" />
            <p>Estimating...</p>
          </div>

          <div v-else class="outcome-body">
            <div class="outcome-ring">
              <RadialScore v-if="readiness.confidence !== null" :value="readiness.confidence" :size="88" />
              <div v-else class="outcome-ring-placeholder">
                <ShieldQuestion :size="30" />
              </div>
              <span class="outcome-ring-badge" :class="`tone-${readiness.tone}`">
                <ShieldCheck v-if="readiness.ready && readiness.isPass" :size="14" />
                <ShieldAlert v-else-if="readiness.ready" :size="14" />
                <ShieldQuestion v-else :size="14" />
              </span>
            </div>
            <div class="outcome-text">
              <strong class="outcome-label">{{ readiness.label }}</strong>
              <p class="outcome-detail">{{ readiness.detail }}</p>
            </div>
          </div>
        </article>

        <article class="surface-card review-card">
          <div class="home-block-head">
            <div class="home-block-title">
              <h3>Performance Review</h3>
              <Info :size="14" class="info-hint" title="Your most recently graded exam." />
            </div>
          </div>

          <div v-if="isInitialLoading" class="home-state-box">
            <RefreshCw :size="20" class="spin-soft" />
            <p>Loading your last result...</p>
          </div>

          <div v-else-if="!lastExamResult" class="home-state-box">
            <ClipboardCheck :size="26" />
            <strong>No results yet</strong>
            <p>Take an exam and your last result will appear here.</p>
          </div>

          <template v-else>
            <div class="review-highlight">
              <strong class="review-highlight-label" :class="`tone-${lastExamResult.scoreTone}`">
                {{ lastExamResult.statusLabel }}:
              </strong>
              <p class="review-exam-title">{{ lastExamResult.title }}</p>
              <p class="review-exam-meta">{{ lastExamResult.subject }} · {{ lastExamResult.scoreDisplay }} · {{ lastExamResult.submittedAtText }}</p>
              <p class="review-hint">{{ lastExamResult.suggestion }}</p>
            </div>

            <button type="button" class="view-analytics-btn" @click="goToAnalytics">
              View Analytics
              <ArrowRight :size="15" />
            </button>
          </template>
        </article>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  AlertCircle,
  ArrowRight,
  Bell,
  BellRing,
  ClipboardCheck,
  Clock3,
  Info,
  Play,
  RefreshCw,
  ShieldAlert,
  ShieldCheck,
  ShieldQuestion,
} from 'lucide-vue-next'
import { useStudentDashboardModule } from '../composables/useStudentDashboardModule'
import RadialScore from '@/components/charts/RadialScore.vue'

const router = useRouter()

const {
  studentFirstName,
  dashboardLoading,
  dashboardLoaded,
  dashboardError,
  notifications,
  lastExamResult,
  readiness,
  loadDashboard,
} = useStudentDashboardModule()

const isInitialLoading = computed(() => dashboardLoading.value && !dashboardLoaded.value)

const activeFilter = ref('all')

// Tabs mirror the notice `category` the composable already derives from its
// due-date / retake / newly-uploaded sort order, so "what's on top" and
// "what's under each tab" never disagree.
const filterTabs = computed(() => {
  const all = notifications.value
  const countOf = (category) => all.filter((notice) => notice.category === category).length

  return [
    { key: 'all', label: 'All', count: all.length },
    { key: 'retake', label: 'Retake', count: countOf('retake') },
    { key: 'new', label: 'New', count: countOf('new') },
    { key: 'due', label: 'Due', count: countOf('due') },
  ]
})

const filteredNotifications = computed(() => (
  activeFilter.value === 'all'
    ? notifications.value
    : notifications.value.filter((notice) => notice.category === activeFilter.value)
))

// One consistent accent icon style for every row — the category pill next
// to the title already carries the color-coding, so the icon just needs to
// say "resume" vs. "start" vs. "wait."
const NOTICE_ICONS = {
  in_progress: RefreshCw,
  retake: RefreshCw,
  available: Play,
  upcoming: Clock3,
}

function noticeIcon(state) {
  return NOTICE_ICONS[state] ?? Bell
}

function goToNotice(notice) {
  router.push({ name: 'dashboard-rooms', query: { room: notice.roomId } })
}

function goToAnalytics() {
  router.push({ name: 'dashboard-analytics' })
}
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.student-home {
  display: flex;
  flex-direction: column;
  gap: 20px;
  font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* Page head — the global topbar already carries the "Dashboard" title, so
   this is just the personal greeting, not a second banner. */
.home-page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  flex-wrap: wrap;
}

.home-page-head h2 {
  margin: 0;
  font-size: 26px;
  font-weight: 800;
  color: var(--lnu-navy);
}

.home-refresh-btn {
  flex-shrink: 0;
}

/* Main grid: notices (left, full height) + outcome/review stacked (right) */
.home-main-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(300px, 1fr);
  gap: 20px;
  align-items: start;
}

.home-side-col {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* Rounder, more generous cards; crisper shadow than the default surface-card blur */
.home-main-grid .surface-card {
  border-radius: 20px;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.06), 0 1px 3px rgba(16, 24, 40, 0.08);
}

.home-block {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 24px 26px;
}

.home-block-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.home-block-title {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--lnu-navy);
}

.home-block-title h3 {
  margin: 0;
  font-size: 19px;
  font-weight: 800;
  color: var(--lnu-navy);
}

.info-hint {
  color: var(--lnu-text-muted);
  cursor: help;
}

/* Filter tabs */
.notice-filter-tabs {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px;
  border-radius: 999px;
  background: #eef0f6;
  border: 1px solid rgba(26, 35, 126, 0.06);
}

.notice-filter-tab {
  border: none;
  background: transparent;
  color: var(--lnu-text-muted);
  font-size: 12.5px;
  font-weight: 700;
  padding: 7px 14px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}

.notice-filter-tab:hover:not(.active) {
  color: var(--lnu-navy);
}

.notice-filter-tab.active {
  background: var(--lnu-gold-light);
  color: var(--lnu-navy);
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.12);
}

.notice-filter-count {
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.12);
  color: inherit;
  font-size: 10px;
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
  padding: 24px 16px;
  border: 1px dashed rgba(26, 35, 126, 0.14);
  border-radius: 14px;
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

/* Notice list — scrollable so it never grows the page */
.notices-block {
  background: #ffffff;
  border-color: rgba(26, 35, 126, 0.08);
  /* Trimmed right padding so the scrollbar can sit flush against the card
     edge instead of floating in the middle of the whitespace; children
     restore the lost space so text still lines up with the header. */
  padding-right: 10px;
}

.notices-block > .home-block-head,
.notices-block > .home-state-box {
  margin-right: 14px;
}

.notice-list {
  list-style: none;
  margin: 0;
  padding: 0 8px 0 0;
  display: grid;
  gap: 12px;
  max-height: 420px;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(26, 35, 126, 0.22) transparent;
}

.notice-list::-webkit-scrollbar {
  width: 6px;
}

.notice-list::-webkit-scrollbar-track {
  background: transparent;
}

.notice-list::-webkit-scrollbar-thumb {
  background: rgba(26, 35, 126, 0.22);
  border-radius: 999px;
}

.notice-list::-webkit-scrollbar-thumb:hover {
  background: rgba(26, 35, 126, 0.34);
}

.notice-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px;
  margin-right: 6px;
  border-radius: 14px;
  background: #f5f6fa;
  cursor: pointer;
  transition: background 0.15s ease;
}

.notice-item:hover,
.notice-item:focus-visible {
  background: #eceffa;
  outline: none;
}

/* One consistent accent color for the icon chip — the pill next to the
   title is what actually carries the category color-coding. */
.notice-icon {
  flex-shrink: 0;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: 1.5px solid rgba(26, 35, 126, 0.3);
  background: #ffffff;
  color: var(--lnu-navy);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.notice-item-body {
  flex: 1;
  min-width: 0;
}

.notice-item-body > strong {
  display: block;
  font-size: 14px;
  font-weight: 700;
  color: var(--lnu-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notice-item-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 7px;
}

.notice-tag {
  font-size: 11px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
  flex-shrink: 0;
}

.tag-due { background: var(--lnu-gold); color: #2b2100; }
.tag-retake { background: var(--lnu-danger); color: #ffffff; }
.tag-new { background: var(--lnu-success); color: #ffffff; }
.tag-other { background: #b0bec5; color: #1a2340; }

.notice-meta-text {
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.notice-meta-room {
  margin-left: auto;
}

/* Estimated outcome card */
.outcome-body {
  display: flex;
  align-items: center;
  gap: 20px;
}

.outcome-ring {
  position: relative;
  flex-shrink: 0;
}

.outcome-ring-placeholder {
  width: 88px;
  height: 88px;
  border-radius: 50%;
  border: 8px solid rgba(26, 35, 126, 0.12);
  display: grid;
  place-items: center;
  color: var(--lnu-text-muted);
}

.outcome-ring-badge {
  position: absolute;
  right: -2px;
  bottom: -2px;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2.5px solid #fff;
  display: grid;
  place-items: center;
  color: #fff;
}

.outcome-ring-badge.tone-success { background: var(--lnu-success); }
.outcome-ring-badge.tone-danger { background: var(--lnu-danger); }
.outcome-ring-badge.tone-neutral { background: #8896aa; }

.outcome-text {
  display: grid;
  gap: 5px;
  min-width: 0;
}

.outcome-label {
  font-size: 16px;
  font-weight: 800;
  color: var(--lnu-text);
}

.outcome-detail {
  margin: 0;
  font-size: 13px;
  line-height: 1.65;
  color: var(--lnu-text-muted);
}

/* Performance review — last exam result */
.review-highlight {
  padding: 16px 18px;
  border-radius: 14px;
  background: #f5f6fa;
  display: grid;
  gap: 4px;
}

.review-highlight-label {
  font-size: 12.5px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lnu-navy);
}

.review-highlight-label.tone-success { color: var(--lnu-success); }
.review-highlight-label.tone-gold { color: #9a6a0c; }
.review-highlight-label.tone-danger { color: var(--lnu-danger); }

.review-exam-title {
  margin: 3px 0 0;
  font-size: 14.5px;
  font-weight: 700;
  color: var(--lnu-text);
}

.review-exam-meta {
  margin: 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.review-hint {
  margin: 5px 0 0;
  font-size: 13px;
  line-height: 1.55;
  color: var(--lnu-text);
}

.view-analytics-btn {
  align-self: flex-start;
  height: 42px;
  border: none;
  border-radius: 12px;
  background: var(--lnu-navy);
  color: #ffffff;
  padding: 0 18px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: 700;
  font-size: 14px;
  transition: background 0.15s ease;
}

.view-analytics-btn:hover {
  background: var(--lnu-navy-light);
}

/* Responsive */
@media (max-width: 1100px) {
  .home-main-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 600px) {
  .home-page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }

  .home-refresh-btn {
    width: 100%;
    justify-content: center;
  }

  .home-block-head {
    flex-direction: column;
    align-items: flex-start;
  }

  .notice-item-meta {
    gap: 8px;
  }

  .notice-meta-room {
    margin-left: 0;
  }

  .view-analytics-btn {
    width: 100%;
    justify-content: center;
  }
}
</style>
