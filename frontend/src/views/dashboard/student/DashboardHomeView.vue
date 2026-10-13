<template>
  <section class="dashboard-view student-home">
    <div v-if="dashboardError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ dashboardError }}</span>
    </div>

    <section class="home-hero">
      <div class="home-hero-content">
        <div v-if="cohortBadgeText || nextUpcomingBadgeText" class="home-badge-row">
          <span v-if="cohortBadgeText" class="home-badge home-badge-solid">
            <GraduationCap :size="13" />
            {{ cohortBadgeText }}
          </span>
          <span v-if="nextUpcomingBadgeText" class="home-badge home-badge-text">
            <Target :size="13" />
            {{ nextUpcomingBadgeText }}
          </span>
        </div>

        <h2 class="home-hero-title">Continue your journey, {{ studentFirstName }}</h2>

        <p class="home-hero-body">
          <template v-if="scoreTrendParts">{{ scoreTrendParts.lead }}<strong>{{ scoreTrendParts.bold }}</strong>{{ scoreTrendParts.tail }}</template>
          <template v-else>Here is what is waiting for you and where you stand.</template>
        </p>
      </div>

      <div class="home-hero-side">
        <div class="home-hero-actions">
          <router-link :to="weakAreaReviewLink" class="home-hero-btn home-hero-btn-solid">
            <Zap :size="14" />
            Start Today's Drill
          </router-link>
          <button type="button" class="home-hero-btn home-hero-btn-outline" @click="goToAnalytics">
            View Analytics
          </button>
        </div>

        <button
          class="home-hero-refresh"
          :disabled="dashboardLoading"
          title="Refresh dashboard"
          aria-label="Refresh dashboard"
          @click="loadDashboard"
        >
          <RefreshCw :size="14" :class="{ 'spin-soft': dashboardLoading }" />
        </button>
      </div>
    </section>

    <div class="home-main-grid">
      <!-- 1. Notices: filterable, scrollable, clickable to the room -->
      <article class="surface-card home-block notices-block">
        <div class="home-block-head">
          <div class="home-block-title-group">
            <div class="home-block-title">
              <Bell :size="17" />
              <h3>Exam Notices</h3>
            </div>
            <p class="home-block-subtitle">Stay updated on scheduled assessments.</p>
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
          <span class="home-state-icon-circle">
            <CheckCircle2 v-if="notifications.length === 0" :size="20" />
            <BellRing v-else :size="19" />
          </span>
          <strong>{{ notifications.length === 0 ? 'Your slate is clean' : 'Nothing in this filter' }}</strong>
          <p>
            {{
              notifications.length === 0
                ? 'No active or pending exam notices. This is a great time to visit Review Bot and strengthen your foundations.'
                : 'Try a different tab above.'
            }}
          </p>
          <div v-if="notifications.length === 0" class="home-state-actions">
            <router-link :to="{ name: 'dashboard-rooms' }" class="home-state-btn-outline">Browse Rooms</router-link>
            <router-link :to="weakAreaReviewLink" class="home-state-link">Practice with Review Bot</router-link>
          </div>
        </div>

        <ul v-else class="notice-list">
          <li
            v-for="notice in filteredNotifications"
            :key="notice.id"
            class="notice-item"
            role="button"
            tabindex="0"
            :aria-label="`${notice.title} — open ${notice.roomName}`"
            @click="goToNotice(notice)"
            @keyup.enter="goToNotice(notice)"
            @keydown.space.prevent="goToNotice(notice)"
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

      <!-- 2. Estimated outcome -->
      <div class="home-side-col">
        <article class="surface-card outcome-card">
          <div class="home-block-head">
            <div class="home-block-title-group">
              <div class="home-block-title">
                <Gauge :size="17" />
                <h3>Estimated Outcome</h3>
              </div>
              <p class="home-block-subtitle">From your latest complete semester</p>
            </div>
          </div>

          <div v-if="isInitialLoading" class="home-state-box">
            <RefreshCw :size="20" class="spin-soft" />
            <p>Estimating...</p>
          </div>

          <template v-else>
            <EstimatedOutcome
              class="outcome-body"
              :readiness="readiness"
              :average-score-display="outcomeStats.averageScoreDisplay"
              :focus-subjects-count="outcomeStats.focusSubjectsCount"
            />
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
  Bell,
  BellRing,
  CheckCircle2,
  Clock3,
  Gauge,
  GraduationCap,
  Play,
  RefreshCw,
  Target,
  Zap,
} from 'lucide-vue-next'
import { useStudentDashboardModule } from '../composables/useStudentDashboardModule'
import EstimatedOutcome from '@/components/EstimatedOutcome.vue'

const router = useRouter()

const {
  studentFirstName,
  dashboardLoading,
  dashboardLoaded,
  dashboardError,
  notifications,
  readiness,
  outcomeStats,
  scoreTrendParts,
  cohortBadgeText,
  nextUpcomingBadgeText,
  weakAreaReviewLink,
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

<style scoped src="./dashboardHome.css"></style>
