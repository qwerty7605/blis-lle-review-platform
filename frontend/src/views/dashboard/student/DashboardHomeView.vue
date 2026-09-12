<template>
  <section class="dashboard-view student-home">
    <div v-if="dashboardError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ dashboardError }}</span>
    </div>

    <section class="home-hero">
      <div class="home-hero-content">
        <div class="home-badge-row">
          <span v-if="cohortBadgeText" class="home-badge home-badge-solid">
            <GraduationCap :size="11" />
            {{ cohortBadgeText }}
          </span>
          <span v-if="cohortBadgeText && nextUpcomingBadgeText" class="home-badge-dot">•</span>
          <span v-if="nextUpcomingBadgeText" class="home-badge home-badge-text">
            <Target :size="12" />
            {{ nextUpcomingBadgeText }}
          </span>
        </div>

        <h1 class="home-hero-title">Continue your journey, {{ studentFirstName }}</h1>

        <p class="home-hero-body">
          <template v-if="scoreTrendParts">{{ scoreTrendParts.lead }}<strong>{{ scoreTrendParts.bold }}</strong>{{ scoreTrendParts.tail }}</template>
          {{ readiness.detail }}
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

      <!-- 2 & 3: estimated outcome and performance feed, stacked in one column -->
      <div class="home-side-col">
        <article class="surface-card outcome-card">
          <div class="home-block-head">
            <div class="home-block-title stacked">
              <h3>Estimated Outcome</h3>
              <p class="home-block-subtitle">Based on recent exam performance</p>
            </div>
            <Info :size="14" class="info-hint" :title="readiness.detail" />
          </div>

          <div v-if="isInitialLoading" class="home-state-box">
            <RefreshCw :size="20" class="spin-soft" />
            <p>Estimating...</p>
          </div>

          <template v-else>
            <div class="outcome-body">
              <div class="outcome-ring">
                <!-- Tone is fixed (not pass/fail-colored): this ring is a
                     neutral confidence gauge, the colored verdict text next
                     to it is the only place sentiment is encoded, so the
                     two can never send a contradicting signal. -->
                <RadialScore
                  v-if="readiness.confidence !== null"
                  :value="readiness.confidence"
                  :size="86"
                  label="Confidence"
                  tone="navy"
                />
                <div v-else class="outcome-ring-placeholder">
                  <ShieldQuestion :size="24" />
                </div>
              </div>

              <div class="outcome-summary">
                <p class="outcome-label" :class="`verdict-${readiness.tone}`">{{ readiness.label }}</p>

                <div class="outcome-stat-row">
                  <div class="outcome-stat">
                    <span class="outcome-stat-label">Average Score</span>
                    <strong class="outcome-stat-value">{{ outcomeStats.averageScoreDisplay }}</strong>
                  </div>
                  <div class="outcome-stat">
                    <span class="outcome-stat-label">Focus Areas</span>
                    <strong class="outcome-stat-value">{{ outcomeStats.focusSubjectsCount }} Subjects</strong>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </article>

        <article class="surface-card review-card">
          <div class="home-block-head">
            <div class="home-block-title-group">
              <div class="home-block-title">
                <h3>Performance Feed</h3>
              </div>
              <p class="home-block-subtitle">Latest results and what to do about them</p>
            </div>
          </div>

          <div v-if="isInitialLoading" class="home-state-box">
            <RefreshCw :size="20" class="spin-soft" />
            <p>Loading your results...</p>
          </div>

          <div v-else-if="performanceFeed.length === 0" class="home-state-box">
            <span class="home-state-icon-circle">
              <ClipboardCheck :size="19" />
            </span>
            <strong>No results yet</strong>
            <p>Take an exam and your results will appear here.</p>
          </div>

          <template v-else>
            <ul class="feed-list">
              <li v-for="item in performanceFeed" :key="item.attempt_id" class="feed-item">
                <div class="feed-item-head">
                  <p class="feed-item-title">{{ item.title }}</p>
                  <strong class="feed-item-score">{{ item.scoreDisplay }}</strong>
                </div>
                <div class="feed-item-meta">
                  <span class="feed-tag">{{ item.subject }}</span>
                  <span class="feed-meta-text">{{ item.submittedAtCompact }}</span>
                  <span class="feed-status-pill" :class="`tone-${item.scoreTone}`">{{ item.statusLabel }}</span>
                </div>
                <p class="feed-quote">{{ item.suggestion }}</p>
              </li>
            </ul>

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
  CheckCircle2,
  ClipboardCheck,
  Clock3,
  GraduationCap,
  Info,
  Play,
  RefreshCw,
  ShieldQuestion,
  Target,
  Zap,
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
  performanceFeed,
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

<style scoped>
.student-home {
  display: flex;
  flex-direction: column;
  gap: 12px;
  /* Fills the flex-column .layout-content box exactly (see DashboardLayout.vue)
     so the hero + grid below can split that fixed height instead of growing
     the page. Falls back to normal block sizing under 901px, where the
     layout switches to plain scroll (see the max-width:900px override there). */
  flex: 1;
  min-height: 0;
  font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* Hero — the global topbar already carries the "Dashboard" title/subtitle,
   so this is the personal greeting plus the two most decision-relevant
   things on the page: "am I on track" and "what should I do right now." */
/* Two columns: where you stand on the left, what to do about it on the
   right, so the primary action sits at the end of the sentence that
   motivates it rather than under a wall of text.

   The navy gradient is the same one the Rooms banner uses -- this hero ran
   navy-to-purple, a tone that appears nowhere else in the system. */
.home-hero {
  position: relative;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  flex-wrap: wrap;
  border-radius: 14px;
  padding: 16px 20px;
  background: linear-gradient(120deg, var(--lnu-navy-deep), var(--lnu-navy) 70%, var(--lnu-navy-light));
  color: #fff;
  box-shadow: 0 10px 24px rgba(13, 21, 71, 0.2);
}

.home-hero-side {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.home-hero-refresh {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.28);
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease;
}

.home-hero-refresh:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.2);
}

.home-hero-refresh:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* Capped by measure rather than by a percentage of the hero: the old 62%
   existed only to clear a decorative graphic that no longer exists, and it
   was throwing away more than a third of the banner's width. */
.home-hero-content {
  min-width: 0;
  flex: 1 1 360px;
  max-width: 80ch;
}

.home-badge-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 7px;
}

.home-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}

.home-badge-solid {
  padding: 4px 10px;
  border-radius: 6px;
  background: rgba(9, 14, 51, 0.55);
  color: #fff;
}

.home-badge-text {
  color: rgba(255, 255, 255, 0.8);
  font-weight: 700;
}

.home-badge-dot {
  color: rgba(255, 255, 255, 0.4);
  font-size: 10px;
}

.home-hero-title {
  margin: 8px 0 0;
  font-size: clamp(17px, 1.6vw, 20px);
  font-weight: 800;
}

.home-hero-body {
  margin: 4px 0 0;
  font-size: 13px;
  line-height: 1.45;
  color: rgba(255, 255, 255, 0.86);
  /* Real sentences here vary in length (score-trend + readiness detail) --
     capped at 2 lines so the hero's height never depends on that text. */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.home-hero-body strong {
  color: #fff;
}

.home-hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.home-hero-btn {
  height: var(--control-height);
  border-radius: var(--control-radius);
  padding: 0 15px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-size: 13px;
  transition: transform 0.15s ease, background 0.15s ease;
}

.home-hero-btn:hover {
  transform: translateY(-1px);
}

.home-hero-btn-solid {
  border: none;
  background: #fff;
  color: var(--lnu-navy);
}

.home-hero-btn-solid:hover {
  background: var(--lnu-gold-pale);
}

.home-hero-btn-outline {
  border: 1.5px solid rgba(255, 255, 255, 0.6);
  background: transparent;
  color: #fff;
}

.home-hero-btn-outline:hover {
  background: rgba(255, 255, 255, 0.12);
}

/* Main grid: notices (left, full height) + outcome/review stacked (right) */
.home-main-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(300px, 1fr);
  gap: 14px;
  /* Stretch (not start) so the notices card matches the height of the
     outcome+review column instead of stopping short and leaving a dead
     gap under it whenever the notice list/empty-state is short. */
  align-items: stretch;
  /* Fills whatever height .home-hero didn't use, so the page's real
     content (notices + outcome + feed) never exceeds one screen -- see
     .student-home. */
  flex: 1;
  min-height: 0;
}

.home-side-col {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-height: 0;
}

/* Flat white, matching every other card in the system. The cream tone here
   was a workaround for .surface-card's old gold gradient banding across a
   tall card; that gradient is gone, so the workaround can go with it. */
.home-main-grid .surface-card {
  border-radius: 14px;
  border: 1px solid var(--border-soft);
  box-shadow: 0 1px 2px rgba(13, 21, 71, 0.05);
  padding: 13px 15px;
  background: var(--surface);
}

.home-block,
.outcome-card,
.review-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 0;
}

/* The feed list is the one part of this column that grows/shrinks with
   content -- give it the leftover height in the side column so it (not
   the page) is what scrolls when there are more than a few results. */
.review-card {
  flex: 1;
}

/* .review-card's flex-basis is 0 (from flex:1 above), which -- with no
   override here -- put ALL of the shrink weight on this card whenever the
   side column ran short on height, squeezing the ring below its own
   88px+padding and clipping it against the card edge. flex-shrink:0 pins
   this card at its natural content height; review-card's own internal
   scroll (see .feed-list) is what absorbs a short column instead. */
.outcome-card {
  flex-shrink: 0;
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
  font-size: 15px;
  font-weight: 800;
  color: var(--ink);
}

.home-block-title.stacked {
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
}

/* Icon+title row plus a subtitle line underneath, used by every card head
   that needs both (Exam Notices, Performance Feed). */
.home-block-title-group {
  display: grid;
  gap: 4px;
}

.home-block-subtitle {
  margin: 0;
  font-size: 11.5px;
  color: var(--ink-soft);
}

.info-hint {
  color: var(--lnu-text-muted);
  cursor: help;
}

/* Filter tabs */
.notice-filter-tabs {
  display: flex;
  align-items: center;
  gap: 3px;
}

.notice-filter-tab {
  border: none;
  background: transparent;
  color: var(--lnu-text-muted);
  font-size: 12px;
  font-weight: 700;
  text-transform: lowercase;
  padding: 5px 10px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}

.notice-filter-tab:hover:not(.active) {
  color: var(--lnu-navy);
}

.notice-filter-tab.active {
  background: var(--lnu-navy);
  color: #fff;
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

.notice-filter-tab.active .notice-filter-count {
  background: rgba(255, 255, 255, 0.24);
}

/* Loading / empty */
.home-state-box {
  display: grid;
  /* place-items only controls alignment *within* each row -- with the
     default align-content (stretch), auto rows expand to fill this box's
     full flex-stretched height, spreading icon/title/paragraph/buttons
     across huge individual gaps instead of sitting together. Packing the
     rows to center as one compact group is what actually removes the
     dead space. */
  align-content: center;
  justify-items: center;
  text-align: center;
  gap: clamp(6px, 1.2vw, 12px);
  padding: 12px;
  border: 1px dashed rgba(26, 35, 126, 0.14);
  border-radius: 12px;
  background: rgba(248, 249, 252, 0.8);
  color: var(--lnu-text-muted);
  /* Card now stretches to match the taller side column (see
     .home-main-grid) -- grow to fill that height and center within it
     instead of leaving blank card padding below a fixed-size box. */
  flex: 1;
  min-height: 0;
}

.home-state-box strong {
  color: var(--lnu-text);
  font-size: clamp(13px, 1.8vw, 16px);
}

.home-state-box p {
  margin: 0;
  max-width: 320px;
  font-size: clamp(12px, 1.4vw, 14px);
  line-height: 1.5;
}

.home-state-icon-circle {
  width: clamp(36px, 6vw, 48px);
  height: clamp(36px, 6vw, 48px);
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: rgba(26, 35, 126, 0.07);
  color: var(--lnu-text-muted);
}

.home-state-actions {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 6px;
}

.home-state-btn-outline {
  height: 30px;
  border: 1.5px solid rgba(26, 35, 126, 0.3);
  border-radius: 8px;
  padding: 0 13px;
  display: inline-flex;
  align-items: center;
  background: #fff;
  color: var(--lnu-navy);
  font-size: 12px;
  font-weight: 700;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.home-state-btn-outline:hover {
  background: rgba(26, 35, 126, 0.05);
  border-color: rgba(26, 35, 126, 0.5);
}

.home-state-link {
  font-size: 13px;
  font-weight: 600;
  color: var(--lnu-text-muted);
}

.home-state-link:hover {
  color: var(--lnu-navy);
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
  align-content: start;
  gap: 8px;
  flex: 1;
  min-height: 0;
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

/* White with a hairline, matching the feed rows opposite it -- these two
   lists sit side by side and previously used different surface treatments
   for the same kind of object. Hover/focus supply the clickable affordance. */
.notice-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 11px;
  margin-right: 6px;
  border-radius: 10px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.notice-item:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 1px;
}

.notice-item:hover,
.notice-item:focus-visible {
  border-color: rgba(26, 35, 126, 0.28);
  background: #f4f7ff;
  outline: none;
}

/* One consistent accent color for the icon chip — the pill next to the
   title is what actually carries the category color-coding. */
.notice-icon {
  flex-shrink: 0;
  width: 30px;
  height: 30px;
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
  font-size: 13px;
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
  gap: 10px;
  margin-top: 4px;
}

.notice-tag {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  flex-shrink: 0;
}

.tag-due { background: var(--lnu-gold); color: #2b2100; }
.tag-retake { background: var(--lnu-danger); color: #ffffff; }
.tag-new { background: var(--lnu-success); color: #ffffff; }
.tag-other { background: var(--lnu-gray-dark); color: #1a2340; }

.notice-meta-text {
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.notice-meta-room {
  margin-left: auto;
}

/* Estimated outcome card — a compact confidence ring next to the verdict
   and one real stat. The page's hero banner already has its own refresh
   action (loadDashboard), so this card doesn't repeat it; the fuller
   explanation lives behind the (i) hint instead of as body text, which
   is what keeps this card (and the feed card stacked under it) short
   enough to fit one screen alongside Exam Notices without scrolling. */
.outcome-body {
  display: flex;
  align-items: center;
  gap: 16px;
}

.outcome-ring {
  position: relative;
  flex-shrink: 0;
}

.outcome-ring-placeholder {
  width: 86px;
  height: 86px;
  border-radius: 50%;
  border: 8px solid rgba(26, 35, 126, 0.12);
  display: grid;
  place-items: center;
  color: var(--lnu-text-muted);
}

.outcome-summary {
  display: grid;
  gap: 10px;
  min-width: 0;
  /* Takes the width the ring doesn't use, so the stat row below can
     actually spread across the card instead of clustering at the left
     with the rest of the card sitting empty. */
  flex: 1;
}

.outcome-label {
  margin: 0;
  font-size: 15px;
  font-weight: 800;
  color: var(--lnu-text);
}

/* Named verdict-* (not the shared tone-* from dashboard.css) on purpose:
   dashboard.css's .tone-danger/.tone-success/.tone-gold utility class also
   sets a pill background, meant for actual badges elsewhere in the app. It
   was bleeding a pink background in here even though this rule only ever
   redeclared color, because dashboard.css is pulled into this component as
   a shared scoped stylesheet too. This is plain colored text, no pill --
   a distinct class name avoids the collision instead of fighting it. */
.outcome-label.verdict-success { color: var(--lnu-success); }
/* Amber, not red -- the ring next to this is a fixed neutral blue (see
   RadialScore usage above), so this word is the only place the verdict's
   sentiment lives; amber reads as "pay attention" without the visual alarm
   of red, matching the rest of this page's restrained warning tone (see
   .feed-status-pill.tone-danger below). */
.outcome-label.verdict-danger { color: #9a6a0c; }
.outcome-label.verdict-neutral { color: var(--lnu-navy); }

.outcome-stat-row {
  display: flex;
  align-items: center;
  gap: 28px;
}

.outcome-stat {
  display: grid;
  gap: 4px;
}

.outcome-stat-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--lnu-text-muted);
}

.outcome-stat-value {
  font-size: 14px;
  font-weight: 800;
  color: var(--lnu-navy);
}

/* Performance feed — every recent graded exam, not just the latest one */
.feed-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  align-content: start;
  gap: 8px;
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(26, 35, 126, 0.22) transparent;
}

.feed-item {
  padding: 9px 11px;
  border-radius: 10px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  display: grid;
  gap: 4px;
}

.feed-item-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.feed-item-title {
  margin: 0;
  flex: 1;
  min-width: 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--lnu-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.feed-item-score {
  flex-shrink: 0;
  font-size: 13px;
  font-weight: 800;
  color: var(--lnu-navy);
}

.feed-item-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.feed-tag {
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  padding: 2px 7px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.08);
  color: var(--lnu-text-muted);
}

.feed-meta-text {
  font-size: 11px;
  color: var(--lnu-text-muted);
}

.feed-status-pill {
  margin-left: auto;
  font-size: 9px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  padding: 2px 8px;
  border-radius: 999px;
}

.feed-status-pill.tone-success { background: rgba(63, 146, 94, 0.16); color: #2c6b44; }
.feed-status-pill.tone-gold { background: rgba(201, 151, 43, 0.18); color: #9a6a0c; }
/* Neutral, not red -- only "Excellent"/"Good" get a color here; "Needs
   Improvement" stays low-key like the .feed-tag chips next to it. */
.feed-status-pill.tone-danger { background: rgba(26, 35, 126, 0.08); color: var(--lnu-text-muted); }

/* One line only -- keeps every feed item the same compact height so the
   list's flex-fill sizing (see .feed-list) stays predictable. */
.feed-quote {
  margin: 1px 0 0;
  padding-left: 8px;
  border-left: 3px solid rgba(26, 35, 126, 0.25);
  font-size: 12px;
  font-style: italic;
  line-height: 1.4;
  color: var(--lnu-text-muted);
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Simple bordered footer action -- the header already has a "View All"
   shortcut, this is the full-width equivalent for the bottom of the list. */
.view-analytics-btn {
  flex-shrink: 0;
  align-self: center;
  height: 32px;
  border: 1.5px solid rgba(26, 35, 126, 0.24);
  border-radius: 9px;
  background: #fff;
  color: var(--lnu-navy);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0 18px;
  font-weight: 700;
  font-size: 13px;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.view-analytics-btn:hover {
  background: rgba(26, 35, 126, 0.05);
  border-color: rgba(26, 35, 126, 0.44);
}

/* Responsive */
@media (max-width: 1100px) {
  .home-main-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 600px) {
  .home-hero {
    padding: 16px;
  }

  .home-hero-content {
    max-width: 100%;
  }

  /* The action pair spans the full width once the hero stacks, with the
     refresh control staying a fixed square beside it. */
  .home-hero-side {
    width: 100%;
    align-items: stretch;
  }

  .home-hero-actions {
    flex: 1;
  }

  .home-hero-btn {
    flex: 1;
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

  .outcome-body {
    flex-direction: column;
    align-items: flex-start;
    gap: 16px;
  }

  .feed-item-head {
    flex-direction: column;
    gap: 2px;
  }
}
</style>
