<template>
  <section class="exam-result-view">
    <div v-if="loadError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ loadError }}</span>
    </div>

    <div v-if="loading" class="room-empty-state">
      <RefreshCw :size="34" class="spin-soft" />
      <h4>Loading your result</h4>
      <p>Please wait while we fetch this attempt.</p>
    </div>

    <div v-else-if="!attempt" class="room-empty-state">
      <AlertCircle :size="28" />
      <h4>Result not available</h4>
      <p>This exam attempt could not be found, or you do not have access to it.</p>
      <button type="button" class="ghost-btn" @click="goBackToRoom">
        <ArrowLeft :size="15" /> Back to Rooms
      </button>
    </div>

    <div v-else-if="attempt.status !== 'submitted'" class="room-empty-state">
      <AlertCircle :size="28" />
      <h4>Not submitted yet</h4>
      <p>This attempt hasn't been submitted, so there's no result to show yet. Resume it from the room to finish.</p>
      <button type="button" class="ghost-btn" @click="goBackToRoom">
        <ArrowLeft :size="15" /> Back to Room
      </button>
    </div>

    <article v-else class="surface-card exam-result-page-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <ClipboardCheck :size="18" />
          <div>
            <h3>{{ exam?.title || 'Exam Result' }}</h3>
            <p class="review-muted">
              {{ room?.name || 'Unknown Room' }}
              <span v-if="room?.code"> • {{ room.code }}</span>
            </p>
          </div>
        </div>
        <button type="button" class="ghost-btn" @click="goBackToRoom">
          <ArrowLeft :size="15" /> Back to Room
        </button>
      </header>

      <!-- An exam taken twice: either sitting can be opened from here. The
           room page only ever links to the latest. -->
      <nav v-if="sittings.length > 1" class="result-sittings" aria-label="Your attempts at this exam">
        <div class="result-sittings-tabs">
          <RouterLink
            v-for="sitting in sittings"
            :key="sitting.id"
            class="result-sitting"
            :class="{ active: sitting.id === attempt.id }"
            :aria-current="sitting.id === attempt.id ? 'page' : undefined"
            :to="{ name: 'dashboard-exam-result', params: { attemptId: sitting.id } }"
          >
            Attempt {{ sitting.number }}
            <span class="result-sitting-score">{{ sittingScore(sitting) }}</span>
          </RouterLink>
        </div>
        <p class="review-muted">
          {{
            currentSittingCounts
              ? 'This is your latest attempt, the score that counts.'
              : 'This is an earlier attempt. Your latest attempt is the score that counts.'
          }}
        </p>
      </nav>

      <section class="exam-result-hero" :class="`is-${summary.tone}`">
        <div class="exam-result-copy">
          <div class="exam-result-badge">
            <CheckCircle2 v-if="summary.score >= 75" :size="18" />
            <AlertCircle v-else :size="18" />
            <span>{{ summary.label }}</span>
          </div>
          <h4>{{ summary.headline }}</h4>
          <p>{{ summary.message }}</p>

          <div class="exam-result-meta">
            <span>Submitted: {{ summary.submittedAtText }}</span>
            <span v-if="exam?.duration_minutes">Time allowed: {{ exam.duration_minutes }} minutes</span>
          </div>
        </div>

        <div class="exam-result-score-panel">
          <span class="exam-result-score-caption">Final Score</span>
          <strong class="exam-result-score-value">{{ summary.scoreDisplay }}%</strong>
          <span class="exam-result-score-subtitle">{{ summary.correct }}/{{ summary.total }} correct</span>
          <span
            v-if="summary.passStatus"
            class="exam-result-pass-chip"
            :class="{ 'is-fail': !summary.passStatus.is_passing }"
          >
            {{ summary.passStatus.is_passing ? 'Passing' : 'Not Passing' }}
            · {{ summary.passStatus.passing_score }}% required
          </span>
        </div>
      </section>

      <div class="exam-result-stats">
        <article class="exam-result-stat">
          <span>Answered</span>
          <strong>{{ summary.answered }}/{{ summary.total }}</strong>
          <small>Completed items</small>
        </article>
        <article class="exam-result-stat">
          <span>Correct</span>
          <strong>{{ summary.correct }}</strong>
          <small>Right answers</small>
        </article>
        <article class="exam-result-stat">
          <span>Incorrect</span>
          <strong>{{ summary.incorrect }}</strong>
          <small>Wrong answers</small>
        </article>
        <article class="exam-result-stat">
          <span>Missed</span>
          <strong>{{ summary.missed }}</strong>
          <small>Unanswered items</small>
        </article>
      </div>

      <div
        v-if="summary.primaryFocus || summary.strength || summary.readinessLevel"
        class="exam-result-insights"
      >
        <article v-if="summary.primaryFocus" class="exam-feedback-card is-focus">
          <span class="exam-feedback-icon is-focus"><Target :size="16" /></span>
          <div class="exam-feedback-card-body">
            <span class="exam-feedback-kicker">
              {{ summary.primaryFocus.basis === 'topic' ? 'Weak Area' : 'Weakest Subject' }}
            </span>
            <strong>{{ summary.primaryFocus.label }}</strong>
            <p>
              {{ summary.primaryFocus.correct_count }}/{{ summary.primaryFocus.total_items }} correct
              <span v-if="summary.primaryFocus.missed_count > 0"> • {{ summary.primaryFocus.missed_count }} missed</span>
            </p>
          </div>
        </article>

        <article v-if="summary.strength" class="exam-feedback-card is-strength">
          <span class="exam-feedback-icon is-strength"><TrendingUp :size="16" /></span>
          <div class="exam-feedback-card-body">
            <span class="exam-feedback-kicker">Strongest Area</span>
            <strong>{{ summary.strength.label }}</strong>
            <p>{{ summary.strength.correct_count }}/{{ summary.strength.total_items }} correct</p>
          </div>
        </article>

        <article v-if="summary.readinessLevel" class="exam-feedback-card">
          <span class="exam-feedback-icon is-readiness"><ShieldCheck :size="16" /></span>
          <div class="exam-feedback-card-body">
            <span class="exam-feedback-kicker">Outcome Level</span>
            <strong>{{ summary.readinessLevel.label }}</strong>
            <p>{{ summary.readinessLevel.message }}</p>
          </div>
        </article>
      </div>

      <section v-if="subjectPerformancePanels.length > 0" class="exam-feedback-panel exam-breakdown-panel">
        <div class="exam-feedback-panel-head">
          <strong>Performance Breakdown</strong>
          <span class="exam-feedback-panel-sub">Weakest subjects and subtopics first</span>
        </div>

        <article v-for="subject in subjectPerformancePanels" :key="subject.subject" class="exam-breakdown-subject">
          <div class="exam-breakdown-subject-head">
            <span class="exam-breakdown-subject-name">{{ subject.subject }}</span>
            <span class="exam-breakdown-subject-score">{{ Number(subject.score_percent ?? 0).toFixed(0) }}%</span>
          </div>

          <div class="exam-score-bar">
            <div
              class="exam-score-bar-fill"
              :class="scoreBarTone(subject.score_percent)"
              :style="{ width: `${Math.min(100, Math.max(0, subject.score_percent ?? 0))}%` }"
            ></div>
          </div>

          <p class="exam-breakdown-subject-meta">
            {{ subject.correct_count }}/{{ subject.total_items }} correct
            <span v-if="subject.missed_count > 0"> • {{ subject.missed_count }} missed</span>
          </p>

          <ul v-if="subject.topics.length > 0" class="exam-topic-breakdown-list">
            <li v-for="topic in subject.topics" :key="`${topic.topic_id}-${topic.subtopic_id || 0}`">
              <div class="exam-topic-row-head">
                <div class="exam-topic-row-name">
                  <span>{{ topic.topic }}</span>
                  <template v-if="topic.subtopic">
                    <ChevronRight :size="12" class="exam-topic-row-arrow" />
                    <span class="exam-topic-row-subtopic">{{ topic.subtopic }}</span>
                  </template>
                </div>
                <span class="pill" :class="topicLevelPillTone(topic.level)">
                  {{ studentExamTopicLevelLabel(topic.level) }}
                  <template v-if="topic.repeated_weak"> • Repeated</template>
                </span>
              </div>
              <div class="exam-score-bar small">
                <div
                  class="exam-score-bar-fill"
                  :class="scoreBarTone(topic.score_percent)"
                  :style="{ width: `${Math.min(100, Math.max(0, topic.score_percent ?? 0))}%` }"
                ></div>
              </div>
            </li>
          </ul>
        </article>
      </section>

      <div v-if="summary.secondaryFocuses.length > 0" class="exam-feedback-tags">
        <span
          v-for="focus in summary.secondaryFocuses"
          :key="`${focus.basis}-${focus.subtopic_id || focus.topic_id || focus.subject}`"
          class="pill neutral"
        >
          Also review {{ focus.label }}
        </span>
      </div>

      <section v-if="summary.recommendedActions.length > 0" class="exam-feedback-panel">
        <div class="exam-feedback-panel-head">
          <Lightbulb :size="16" />
          <strong>How To Improve Next</strong>
        </div>
        <ol class="exam-feedback-list">
          <li v-for="(action, actionIndex) in summary.recommendedActions" :key="`exam-result-feedback-${actionIndex}`">
            <span class="exam-feedback-list-index">{{ actionIndex + 1 }}</span>
            <span>{{ action }}</span>
          </li>
        </ol>
        <p v-if="summary.historicalReference?.matches_primary_focus" class="exam-feedback-trend">
          This focus area also matches your recent performance trend.
        </p>
      </section>

      <div class="exam-result-actions">
        <button type="button" class="ghost-btn" @click="reviewAnswers">
          {{ reviewActionLabel }}
        </button>
        <button v-if="summary.primaryFocus" type="button" class="primary-btn" @click="goToReviewWeakArea">
          Review Your Weak Areas
        </button>
        <button type="button" class="ghost-btn" @click="goBackToRoom">
          Done
        </button>
      </div>
    </article>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { AlertCircle, ArrowLeft, CheckCircle2, ChevronRight, ClipboardCheck, Lightbulb, RefreshCw, ShieldCheck, Target, TrendingUp } from 'lucide-vue-next'
import { studentExamsApi } from '@/api/studentExams.api'
import {
  buildExamResultSummary,
  buildSubjectPerformancePanels,
  scoreBarTone,
  studentExamTopicLevelLabel,
  topicLevelPillTone,
} from '../composables/examResultSummary'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const loadError = ref('')
const attempt = ref(null)
const exam = ref(null)
const room = ref(null)
const feedback = ref(null)
const questionsCount = ref(0)
// Every submitted sitting of this exam, oldest first.
const sittings = ref([])

const currentSittingCounts = computed(() => (
  sittings.value.find((sitting) => sitting.id === attempt.value?.id)?.counts ?? true
))

function sittingScore(sitting) {
  return sitting.score_percent === null || sitting.score_percent === undefined
    ? 'Not graded'
    : `${Number(sitting.score_percent).toFixed(1)}%`
}

const summary = computed(() => buildExamResultSummary({
  attempt: attempt.value,
  feedback: feedback.value,
  questionsCount: questionsCount.value,
}))

const subjectPerformancePanels = computed(() => buildSubjectPerformancePanels(summary.value))

const reviewActionLabel = computed(() => (
  String(exam.value?.results_visibility_mode ?? 'hidden') === 'visible'
    ? 'Review Answers'
    : 'Review Responses'
))

async function loadResult() {
  const attemptId = Number(route.params.attemptId)

  if (!Number.isFinite(attemptId) || attemptId < 1) {
    loadError.value = 'Invalid exam attempt.'
    return
  }

  loading.value = true
  loadError.value = ''

  try {
    const { data } = await studentExamsApi.getAttempt(attemptId)
    attempt.value = data?.attempt ?? null
    exam.value = data?.exam ?? null
    room.value = data?.room ?? null
    feedback.value = data?.feedback ?? null
    questionsCount.value = Array.isArray(data?.questions) ? data.questions.length : 0
    sittings.value = Array.isArray(data?.sittings) ? data.sittings : []
  } catch (error) {
    attempt.value = null
    loadError.value = firstApiError(error, 'Unable to load this exam result.')
  } finally {
    loading.value = false
  }
}

function goBackToRoom() {
  router.push(room.value?.id ? { name: 'dashboard-rooms', query: { room: room.value.id } } : { name: 'dashboard-rooms' })
}

// Hands off to the live exam-taking page, which opens straight into
// per-question review mode for this exam when it sees these query params
// (see useRoomsModule.js's onMounted deep-link handling) -- the immersive
// question-by-question review UI (bookmarks, jump palette) stays there
// rather than being duplicated on this results page.
function reviewAnswers() {
  if (!room.value?.id || !exam.value?.id) return

  router.push({
    name: 'dashboard-rooms',
    // The attempt is named so the review opens this one, not whichever
    // attempt the room page would open for the exam.
    query: { room: room.value.id, exam: exam.value.id, attempt: attempt.value?.id, review: '1' },
  })
}

function goToReviewWeakArea() {
  const focus = summary.value.primaryFocus
  if (!focus) return

  router.push({
    name: 'dashboard-review-bot',
    query: {
      subject: focus.subject,
      ...(focus.basis === 'topic' && focus.topic_id ? { topic_id: focus.topic_id } : {}),
      ...(focus.basis === 'topic' && focus.subtopic_id ? { subtopic_id: focus.subtopic_id } : {}),
    },
  })
}

onMounted(loadResult)

// Switching to the other sitting keeps this page mounted; only the id changes.
watch(() => route.params.attemptId, (next, previous) => {
  if (next && next !== previous) loadResult()
})
</script>

<style scoped>
.exam-result-view {
  display: grid;
  gap: 16px;
}

.exam-result-page-card {
  display: grid;
  gap: 18px;
  padding: 22px;
}

/* The room line under the exam's name. It kept the browser's paragraph
   margins, a blank line above and below it. */
.exam-result-page-card .room-page-title p {
  margin: 3px 0 0;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

/* Large figures set with a line-height of 1 are taller than their own line;
   1.15 is the least that holds them. */
.exam-result-copy h4,
.exam-result-score-value,
.exam-result-stat strong {
  line-height: 1.15;
}

@media (max-width: 640px) {
  .exam-result-page-card {
    gap: 14px;
    padding: 14px;
  }

  /* The exam's name over its room, then Back at its own width. */
  .exam-result-page-card > .room-page-head {
    gap: 10px;
    margin-bottom: 0;
    padding: 0 0 14px;
  }

  .exam-result-page-card .room-page-title {
    align-items: flex-start;
  }

  .exam-result-page-card .room-page-title svg {
    flex-shrink: 0;
    margin-top: 3px;
  }

  .exam-result-page-card > .room-page-head > .ghost-btn {
    align-self: flex-start;
  }

  /* Four numbers in two rows. One to a row, they filled a screen before
     the breakdown began. */
  .exam-result-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .exam-result-stat {
    gap: 4px;
    padding: 12px;
    border-radius: 14px;
  }

  .exam-result-stat span {
    font-size: 11px;
    letter-spacing: 0.06em;
  }
}

/* The two sittings of an exam that was retaken. */
.result-sittings {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 8px;
  margin: 0 18px 14px;
}

.result-sittings p {
  margin: 0;
}

.result-sittings-tabs {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  border-radius: 999px;
  background: #eef1f8;
  border: 1px solid rgba(13, 21, 71, 0.08);
  justify-self: start;
  max-width: 100%;
}

.result-sitting {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 34px;
  padding: 0 14px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-soft);
  text-decoration: none;
  white-space: nowrap;
}

.result-sitting.active {
  background: var(--panel-navy);
  color: #ffffff;
}

.result-sitting-score {
  font-variant-numeric: tabular-nums;
  font-weight: 700;
}

@media (max-width: 640px) {
  .result-sittings {
    margin-inline: 14px;
  }

  .result-sittings-tabs {
    display: flex;
    justify-self: stretch;
  }

  .result-sitting {
    flex: 1 1 0;
    padding: 0 8px;
  }
}

@media (pointer: coarse) {
  .result-sitting {
    min-height: 40px;
  }
}
</style>
