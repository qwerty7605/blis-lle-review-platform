<template>
  <section class="review-bot-view">
    <div v-if="sessionError" class="feedback danger" role="alert">
      <AlertCircle :size="15" />
      <span>{{ sessionError }}</span>
    </div>

    <div v-if="catalogError" class="feedback danger" role="alert">
      <AlertCircle :size="15" />
      <span>{{ catalogError }}</span>
    </div>

    <div class="review-grid">
      <!-- Setup: fixed head, scrolling body, pinned action bar -->
      <aside class="surface-card review-panel review-setup" aria-labelledby="review-setup-heading">
        <header class="review-panel-head">
          <div class="review-panel-head-copy">
            <h2 id="review-setup-heading">Setup <InfoTip text="Practice sets are drawn from the review questions your teachers have published." /></h2>
          </div>
          <span class="review-head-badge">{{ form.questionCount }} items</span>
        </header>

        <div class="review-setup-body">
          <section class="review-group">
            <span class="review-group-label">Mode</span>

            <div class="review-segmented" role="group" aria-label="Review mode">
              <button
                type="button"
                class="review-segment"
                :class="{ active: mode === 'manual' }"
                :aria-pressed="(mode === 'manual').toString()"
                @click="setMode('manual')"
              >
                Subject / Topic
              </button>
              <button
                type="button"
                class="review-segment"
                :class="{ active: mode === 'weak_area' }"
                :aria-pressed="(mode === 'weak_area').toString()"
                :disabled="!weakArea"
                :title="weakArea ? '' : 'Needs a few more graded exams first'"
                @click="setMode('weak_area')"
              >
                <Target :size="12" />
                Weak Areas
              </button>
            </div>

            <div v-if="mode === 'weak_area'" class="review-weak-box">
              <template v-if="weakArea">
                <span class="review-weak-kicker">Detected focus area</span>
                <strong>{{ weakAreaLabel }}</strong>
                <p v-if="weakAreaScoreText">Previous performance: {{ weakAreaScoreText }}</p>
              </template>
              <p v-else class="review-weak-empty">
                Take and submit a few more exams first — we need some history to detect a weak area.
              </p>
            </div>
          </section>

          <template v-if="mode === 'manual'">
            <section class="review-group">
              <span class="review-group-label">Subject</span>

              <div v-if="catalogLoading" class="review-inline-empty">
                <RefreshCw :size="16" class="spin-soft" />
                <p>Loading subjects...</p>
              </div>

              <div v-else-if="readySubjects.length === 0" class="review-inline-empty">
                <BookOpen :size="18" />
                <p>No published questions yet. Check back after your teacher publishes some.</p>
              </div>

              <div v-else class="review-subject-grid">
                <button
                  v-for="subject in readySubjects"
                  :key="subject.subject"
                  type="button"
                  class="review-subject-chip"
                  :class="{ 'is-selected': form.subject === subject.subject }"
                  :aria-pressed="(form.subject === subject.subject).toString()"
                  @click="selectSubject(subject.subject)"
                >
                  {{ shortSubjectLabel(subject.subject) }}
                </button>
              </div>
            </section>

            <section v-if="availableTopics.length > 0" class="review-group">
              <div class="review-group-labelrow">
                <span class="review-group-label">Topic</span>
                <button v-if="form.topicId" type="button" class="review-clear-link" @click="selectTopic(null)">Clear</button>
              </div>

              <div class="review-chip-list">
                <button
                  v-for="topic in availableTopics"
                  :key="topic.id"
                  type="button"
                  class="review-chip"
                  :class="{ 'is-selected': form.topicId === topic.id }"
                  :aria-pressed="(form.topicId === topic.id).toString()"
                  @click="selectTopic(form.topicId === topic.id ? null : topic.id)"
                >
                  {{ topic.name }}
                </button>
              </div>
            </section>

            <section v-if="availableSubtopics.length > 0" class="review-group">
              <div class="review-group-labelrow">
                <span class="review-group-label">Subtopic</span>
                <button v-if="form.subtopicId" type="button" class="review-clear-link" @click="selectSubtopic(null)">Clear</button>
              </div>

              <div class="review-chip-list">
                <button
                  v-for="subtopic in availableSubtopics"
                  :key="subtopic.id"
                  type="button"
                  class="review-chip"
                  :class="{ 'is-selected': form.subtopicId === subtopic.id }"
                  :aria-pressed="(form.subtopicId === subtopic.id).toString()"
                  @click="selectSubtopic(form.subtopicId === subtopic.id ? null : subtopic.id)"
                >
                  {{ subtopic.name }}
                </button>
              </div>
            </section>

            <section v-if="availableDifficulties.length > 0" class="review-group">
              <span class="review-group-label">Difficulty</span>

              <div class="review-chip-row">
                <button
                  type="button"
                  class="review-chip"
                  :class="{ 'is-selected': !form.difficulty }"
                  @click="setDifficulty('')"
                >
                  Any
                </button>
                <button
                  v-for="difficulty in availableDifficulties"
                  :key="difficulty"
                  type="button"
                  class="review-chip is-capitalized"
                  :class="{ 'is-selected': form.difficulty === difficulty }"
                  @click="setDifficulty(difficulty)"
                >
                  {{ difficulty }}
                </button>
              </div>
            </section>
          </template>

          <section class="review-group">
            <span class="review-group-label">Items</span>

            <div class="review-chip-row">
              <button
                v-for="count in questionCountOptions"
                :key="count"
                type="button"
                class="review-chip is-numeric"
                :class="{ 'is-selected': form.questionCount === count }"
                @click="setQuestionCount(count)"
              >
                {{ count }}
              </button>
            </div>
          </section>
        </div>

        <footer class="review-setup-foot">
          <button
            type="button"
            class="primary-btn review-start-btn"
            :disabled="sessionStarting || !canStart"
            @click="startSession"
          >
            <Sparkles :size="15" />
            {{ sessionStarting ? 'Starting...' : 'Start Review' }}
          </button>

          <button v-if="hasSession" type="button" class="ghost-btn review-clear-btn" :disabled="sessionStarting" @click="startAnother">
            Clear
          </button>
        </footer>
      </aside>

      <!-- Session: fixed head, scrolling question list, pinned submit bar -->
      <section ref="sessionPanelRef" class="surface-card review-panel review-session" aria-labelledby="review-session-heading">
        <header class="review-panel-head">
          <div class="review-panel-head-copy">
            <h2 id="review-session-heading">Review</h2>
            <p v-if="hasSession">{{ session.subject }}</p>
            <p v-else>Nothing running yet</p>
          </div>

          <div v-if="hasSession && !result" class="review-progress">
            <span class="review-progress-value">{{ answeredCount }}<em>/{{ session.questions.length }}</em></span>
            <div class="review-progress-track" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100">
              <div class="review-progress-fill" :style="{ width: `${progressPercent}%` }" />
            </div>
          </div>

          <span v-else-if="result" class="review-head-badge">Submitted</span>
        </header>

        <div class="review-session-body">
          <div v-if="hasSession && session.scopeNote && !result" class="review-note">
            <Info :size="14" />
            <span>{{ session.scopeNote }}</span>
          </div>

          <div v-if="sessionStarting" class="review-empty">
            <RefreshCw :size="24" class="spin-soft" />
            <h3>Preparing your set</h3>
            <p>Pulling questions for the filters you picked.</p>
          </div>

          <div v-else-if="!hasSession" class="review-empty">
            <span class="review-empty-icon"><Sparkles :size="22" /></span>
            <h3>No review session yet</h3>
            <p>Pick a subject on the left — or let us choose your weak areas — then start a review.</p>
          </div>

          <template v-else>
            <div v-if="result" class="review-result" :class="scoreToneClass(result.scorePercent)">
              <div class="review-result-score">
                <span class="review-result-kicker">Score</span>
                <strong>{{ result.scorePercent }}%</strong>
                <span class="review-result-count">{{ result.correctAnswers }} / {{ result.totalItems }} correct</span>
              </div>

              <div class="review-result-copy">
                <span v-if="result.improved !== null" class="review-improved" :class="{ down: result.improved === false }">
                  {{ result.improved ? '▲ Improved from last time' : '▼ Lower than last time' }}
                </span>

                <template v-if="result.recommendation">
                  <strong>{{ recommendationHeading(result.recommendation.action) }}</strong>
                  <p>{{ result.recommendation.message }}</p>
                </template>

                <div class="review-result-actions">
                  <button
                    v-if="result.recommendation?.action === 'repeat_topic'"
                    type="button"
                    class="primary-btn"
                    @click="repeatSameTopic"
                  >
                    Review This Topic Again
                  </button>
                  <button
                    v-else-if="result.recommendation?.action === 'next_topic'"
                    type="button"
                    class="primary-btn"
                    @click="reviewNextTopic"
                  >
                    Review Next Weak Topic
                  </button>
                  <button type="button" class="ghost-btn" @click="startAnother">
                    Start a New Review
                  </button>
                </div>
              </div>
            </div>

            <div class="review-question-scroll">
              <div class="review-question-list">
                <article v-for="question in session.questions" :key="question.questionId" class="review-question-card">
                  <div class="review-question-head">
                    <span class="review-question-number">Question {{ question.itemNumber }}</span>

                    <span v-if="result" class="pill" :class="questionResultClass(question)">
                      {{ questionResultLabel(question) }}
                    </span>
                  </div>

                  <p class="review-question-text">{{ question.questionText }}</p>

                  <div class="review-option-list">
                    <label
                      v-for="option in question.options"
                      :key="option.id"
                      class="review-option"
                      :class="{ 'is-selected': answers[question.questionId] === option.id }"
                    >
                      <input
                        :checked="answers[question.questionId] === option.id"
                        type="radio"
                        :name="`review-question-${question.questionId}`"
                        :value="option.id"
                        :disabled="Boolean(result)"
                        @change="answerQuestion(question.questionId, option.id)"
                      />
                      <span>{{ option.text }}</span>
                    </label>
                  </div>

                  <div v-if="result && missedItemFor(question)" class="review-explanation">
                    <strong>Correct answer: {{ missedItemFor(question).correct_answer }}</strong>
                    <p v-if="missedItemFor(question).explanation">{{ missedItemFor(question).explanation }}</p>
                  </div>
                </article>
              </div>
            </div>

            <footer v-if="!result" class="review-session-foot">
              <span class="review-foot-hint">
                {{ allAnswered ? 'All items answered.' : `${session.questions.length - answeredCount} item(s) left.` }}
              </span>

              <button
                type="button"
                class="primary-btn"
                :disabled="submitting || !allAnswered"
                :title="allAnswered ? '' : 'Answer every question before submitting'"
                @click="submitSession"
              >
                {{ submitting ? 'Submitting...' : 'Submit Review' }}
              </button>
            </footer>
          </template>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup>
import InfoTip from '@/components/InfoTip.vue'
import { computed, nextTick, ref, watch } from 'vue'
import { AlertCircle, BookOpen, Info, RefreshCw, Sparkles, Target } from 'lucide-vue-next'
import { useReviewBotModule } from '../composables/useReviewBotModule'

// Kept in step with the 1000px rule in this file's styles: from there down
// the two panels sit one above the other and the page scrolls.
const STACKED_QUERY = '(max-width: 1000px)'

const {
  catalogLoading,
  catalogError,
  readySubjects,
  weakArea,
  weakAreaLabel,
  weakAreaScoreText,
  mode,
  form,
  questionCountOptions,
  availableTopics,
  availableSubtopics,
  availableDifficulties,
  canStart,
  sessionStarting,
  sessionError,
  session,
  hasSession,
  answers,
  answeredCount,
  allAnswered,
  submitting,
  result,
  selectSubject,
  selectTopic,
  selectSubtopic,
  setDifficulty,
  setQuestionCount,
  setMode,
  startSession,
  answerQuestion,
  submitSession,
  startAnother,
  reviewNextTopic,
  repeatSameTopic,
} = useReviewBotModule()

/*
 * Stacked, the questions open under the setup panel and the score lands above
 * the last question: both off-screen from where the button that caused them
 * was pressed. So the review panel is brought to the top of the screen when
 * a set arrives and again when it is scored. Side by side, both are already
 * in view and nothing moves.
 */
const sessionPanelRef = ref(null)

async function showSessionPanel() {
  if (typeof window === 'undefined' || !window.matchMedia(STACKED_QUERY).matches) return

  await nextTick()
  sessionPanelRef.value?.scrollIntoView({
    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
    block: 'start',
  })
}

watch(() => session.value?.attemptId, (attemptId) => {
  if (attemptId) showSessionPanel()
})

watch(result, (value) => {
  if (value) showSessionPanel()
})

// Drives the header progress bar, which replaced the old full-width
// "Progress / 0 of 10" box -- same two numbers, but folded into the panel
// header so the question list keeps that vertical space.
const progressPercent = computed(() => {
  const total = session.value?.questions?.length ?? 0
  if (total === 0) return 0

  return Math.round((answeredCount.value / total) * 100)
})

const shortSubjectLookup = {
  'Cataloging and Classification': 'Cataloging',
  'Indexing and Abstracting': 'Indexing',
  'Information Technology': 'IT',
  'Reference Services': 'Reference',
  'Library Management': 'Management',
  'Selection and Acquisition': 'Acquisition',
}

function shortSubjectLabel(subject) {
  return shortSubjectLookup[subject] ?? subject
}

function scoreToneClass(score) {
  if (score >= 85) return 'strong'
  if (score >= 75) return 'steady'
  return 'focus'
}

function missedItemFor(question) {
  if (!result.value) return null
  return result.value.missedItems.find((item) => item.item_number === question.itemNumber) ?? null
}

function questionResultLabel(question) {
  return missedItemFor(question) ? 'Review' : 'Correct'
}

function questionResultClass(question) {
  return missedItemFor(question) ? 'gold' : 'success'
}

function recommendationHeading(action) {
  if (action === 'repeat_topic') return 'Keep practicing this topic'
  if (action === 'next_topic') return 'Ready for the next one'
  if (action === 'keep_going') return 'Nice work'
  return 'Keep it up'
}
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
/*
  Fits one screen with no page-level scroll: .review-bot-view fills the
  flex-column .layout-content box (see DashboardLayout.vue's is-fill-screen
  chain, which this route opts into), the two panels take that fixed height,
  and only the panels' own bodies scroll -- the setup list and the question
  list. Each panel is head / scroll / footer, so the Start and Submit actions
  stay put instead of being scrolled away.

  The page head card this view used to carry is gone: the shared layout
  topbar already renders "Review Bot" with the same description, and a
  second 34px title was the single biggest reason the page overflowed.
*/

.review-bot-view {
  display: flex;
  flex-direction: column;
  gap: 10px;
  flex: 1;
  min-height: 0;
}

.review-bot-view > .feedback {
  flex-shrink: 0;
}

.review-grid {
  display: grid;
  grid-template-columns: minmax(0, 330px) minmax(0, 1fr);
  gap: 14px;
  flex: 1;
  min-height: 0;
}

/* padding:0 (the shared .surface-card sets 18px) -- head, body and footer
   pad themselves, so the scroll area can run edge to edge and its scrollbar
   sits against the panel border rather than floating inside a gutter.
   overflow:hidden keeps that scroll area inside the rounded corners. */
.review-panel {
  display: flex;
  flex-direction: column;
  min-height: 0;
  padding: 0;
  overflow: hidden;
}

/* Plain white panels, one accent (navy) for what is chosen: the page used to
   give every subject its own colour, which read as decoration, not meaning. */
.review-setup,
.review-session {
  background: #fff;
}

/* ---- Panel head ---- */
.review-panel-head {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 15px;
  border-bottom: 1px solid rgba(13, 21, 71, 0.08);
}

.review-panel-head-copy {
  min-width: 0;
}

.review-panel-head h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 800;
  color: var(--lnu-navy-deep);
}

.review-panel-head p {
  margin: 2px 0 0;
  font-size: 11.5px;
  color: var(--lnu-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.review-head-badge {
  flex-shrink: 0;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.09);
  color: var(--lnu-navy-deep);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  white-space: nowrap;
}

.review-progress {
  flex-shrink: 0;
  display: grid;
  gap: 5px;
  justify-items: end;
  min-width: 96px;
}

.review-progress-value {
  font-size: 15px;
  font-weight: 800;
  color: var(--lnu-navy-deep);
  line-height: 1;
}

.review-progress-value em {
  font-style: normal;
  font-size: 12px;
  font-weight: 700;
  color: var(--lnu-text-muted);
}

.review-progress-track {
  width: 96px;
  height: 5px;
  border-radius: 999px;
  background: rgba(13, 21, 71, 0.1);
  overflow: hidden;
}

.review-progress-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, var(--lnu-navy), var(--lnu-navy-light));
  transition: width 0.3s ease;
}

/* ---- Setup body ---- */
.review-setup-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 14px 15px;
  display: grid;
  gap: 15px;
  align-content: start;
  scrollbar-width: thin;
  scrollbar-color: rgba(26, 35, 126, 0.22) transparent;
}

/* Flat labelled groups rather than a white card per field: six nested cards
   inside an already-carded panel was the bulk of this column's height, and
   the borders added no grouping the label wasn't already giving. */
.review-group {
  display: grid;
  gap: 8px;
}

.review-group-labelrow {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.review-group-label {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #5d6b86;
}

.review-clear-link {
  border: none;
  background: transparent;
  padding: 0;
  font-size: 11px;
  font-weight: 700;
  color: var(--lnu-navy);
  text-decoration: underline;
  text-underline-offset: 2px;
}

/* ---- Segmented mode switch ---- */
.review-segmented {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 3px;
  padding: 3px;
  border-radius: 10px;
  background: rgba(13, 21, 71, 0.06);
}

.review-segment {
  height: 32px;
  border: none;
  border-radius: 8px;
  background: transparent;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 700;
  color: #5d6b86;
  transition: background 0.15s ease, color 0.15s ease;
}

.review-segment.active {
  background: #fff;
  color: var(--lnu-navy);
  box-shadow: 0 1px 3px rgba(13, 21, 71, 0.14);
}

.review-segment:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.review-weak-box {
  display: grid;
  gap: 3px;
  padding: 11px 12px;
  border-radius: 10px;
  background: #f6f7f9;
  border: 1px solid rgba(13, 21, 71, 0.1);
}

.review-weak-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #5d6b86;
}

.review-weak-box strong {
  font-size: 13.5px;
  color: var(--lnu-navy-deep);
  line-height: 1.35;
}

.review-weak-box p {
  margin: 0;
  font-size: 11.5px;
  color: var(--lnu-text-muted);
}

.review-weak-empty {
  line-height: 1.5;
}

/* ---- Subject chips ----
   No colour per subject: white like the other chips, navy once chosen. */
.review-subject-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 7px;
}

.review-subject-chip {
  border-radius: 10px;
  padding: 9px 8px;
  font-size: 12px;
  font-weight: 700;
  text-align: center;
  border: 1px solid rgba(13, 21, 71, 0.12);
  background: #fff;
  color: var(--lnu-navy-deep);
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.review-subject-chip:hover:not(.is-selected) {
  border-color: rgba(13, 21, 71, 0.26);
  background: #f6f7f9;
}

.review-subject-chip.is-selected {
  background: var(--lnu-navy);
  border-color: var(--lnu-navy);
  color: #fff;
}

/* ---- Shared chips (topic, subtopic, difficulty, item count) ---- */
.review-chip-list,
.review-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

/* Long topic lists scroll in place rather than pushing the Items group and
   the Start button out of view. Tall enough that an ordinary subject's
   topics all show: at 124px six of them were a three-row window. */
.review-chip-list {
  max-height: min(260px, 34vh);
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(26, 35, 126, 0.22) transparent;
}

.review-chip {
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 999px;
  background: #fff;
  color: var(--lnu-text);
  padding: 6px 11px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.3;
  text-align: left;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.review-chip:hover:not(.is-selected) {
  border-color: rgba(13, 21, 71, 0.26);
  background: #f6f7f9;
}

.review-chip.is-selected {
  background: var(--lnu-navy);
  border-color: var(--lnu-navy);
  color: #fff;
}

.review-chip.is-capitalized {
  text-transform: capitalize;
}

.review-chip.is-numeric {
  min-width: 40px;
  text-align: center;
  font-weight: 700;
}

.review-inline-empty {
  display: grid;
  justify-items: center;
  gap: 6px;
  padding: 14px 12px;
  border-radius: 10px;
  border: 1px dashed rgba(13, 21, 71, 0.16);
  background: rgba(255, 255, 255, 0.6);
  text-align: center;
  color: var(--lnu-text-muted);
}

.review-inline-empty p {
  margin: 0;
  font-size: 11.5px;
  line-height: 1.5;
}

/* ---- Setup footer ---- */
.review-setup-foot {
  flex-shrink: 0;
  display: flex;
  gap: 8px;
  padding: 11px 15px;
  border-top: 1px solid rgba(13, 21, 71, 0.08);
  background: rgba(255, 255, 255, 0.72);
}

.review-start-btn {
  flex: 1;
  height: 36px;
  justify-content: center;
  gap: 6px;
}

.review-clear-btn {
  flex-shrink: 0;
  height: 36px;
}

/* ---- Session body ---- */
.review-session-body {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.review-note {
  flex-shrink: 0;
  display: flex;
  align-items: flex-start;
  gap: 7px;
  margin: 12px 15px 0;
  padding: 9px 11px;
  border-radius: 10px;
  background: #f6f7f9;
  border: 1px solid rgba(13, 21, 71, 0.1);
  color: #46526b;
  font-size: 11.5px;
  line-height: 1.5;
}

.review-empty {
  flex: 1;
  min-height: 0;
  display: grid;
  align-content: center;
  justify-items: center;
  gap: 7px;
  padding: 24px;
  text-align: center;
  color: var(--lnu-text-muted);
}

.review-empty-icon {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(13, 21, 71, 0.06);
  color: var(--lnu-navy);
}

.review-empty h3 {
  margin: 2px 0 0;
  font-size: 14px;
  font-weight: 800;
  color: var(--lnu-navy-deep);
}

.review-empty p {
  margin: 0;
  font-size: 12px;
  line-height: 1.55;
  max-width: 34ch;
}

/* ---- Result ---- */
.review-result {
  flex-shrink: 0;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 14px;
  margin: 12px 15px 0;
  padding: 13px 14px;
  border-radius: 14px;
  border: 1px solid rgba(13, 21, 71, 0.1);
}

/* The score is the same neutral box whatever it is; the number says enough. */
.review-result {
  background: #f8f9fb;
}

.review-result-score {
  display: grid;
  gap: 1px;
  align-content: center;
  justify-items: center;
  min-width: 92px;
  padding-right: 14px;
  border-right: 1px solid rgba(13, 21, 71, 0.1);
}

.review-result-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #5d6b86;
}

.review-result-score strong {
  font-size: 28px;
  line-height: 1.1;
  color: var(--lnu-navy-deep);
}

.review-result-count {
  font-size: 11px;
  color: var(--lnu-text-muted);
  white-space: nowrap;
}

.review-result-copy {
  min-width: 0;
  display: grid;
  gap: 3px;
  align-content: center;
}

.review-result-copy strong {
  font-size: 13px;
  color: var(--lnu-navy-deep);
}

.review-result-copy p {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--lnu-text-muted);
}

.review-improved {
  font-size: 11px;
  font-weight: 800;
  color: #3949ab;
}

/* Amber, not red: the app's below-passing colour (see JsChart.vue). */
.review-improved.down {
  color: #96600f;
}

.review-result-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
  margin-top: 6px;
}

.review-result-actions .primary-btn,
.review-result-actions .ghost-btn {
  height: 30px;
  padding: 0 12px;
  font-size: 12px;
}

/* ---- Question list ---- */
.review-question-scroll {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 12px 15px;
  scrollbar-gutter: stable;
  scrollbar-width: thin;
  scrollbar-color: rgba(26, 35, 126, 0.22) transparent;
}

.review-question-list {
  display: grid;
  gap: 10px;
}

.review-question-card {
  display: grid;
  gap: 9px;
  padding: 13px 14px;
  border-radius: 14px;
  border: 1px solid rgba(13, 21, 71, 0.1);
  background: #fff;
}

.review-question-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.review-question-number {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: #5d6b86;
}

.review-question-text {
  margin: 0;
  font-size: 14.5px;
  line-height: 1.55;
  color: var(--lnu-text);
}

.review-option-list {
  display: grid;
  gap: 6px;
}

.review-option {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  padding: 9px 11px;
  border: 1px solid rgba(13, 21, 71, 0.11);
  border-radius: 10px;
  background: #fff;
  color: var(--lnu-text);
  font-size: 13px;
  line-height: 1.45;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.review-option:hover:not(.is-selected) {
  background: #f6f7f9;
}

.review-option.is-selected {
  border-color: var(--lnu-navy);
  box-shadow: inset 0 0 0 1px var(--lnu-navy);
  background: rgba(26, 35, 126, 0.04);
}

.review-option input {
  margin-top: 2px;
  accent-color: var(--lnu-navy);
  flex-shrink: 0;
}

.review-explanation {
  display: grid;
  gap: 3px;
  padding: 10px 12px;
  border-radius: 10px;
  background: #f6f7f9;
  border: 1px solid rgba(13, 21, 71, 0.1);
}

.review-explanation strong {
  font-size: 12.5px;
  color: var(--lnu-navy-deep);
}

.review-explanation p {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--lnu-text-muted);
}

/* ---- Session footer ---- */
.review-session-foot {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 11px 15px;
  border-top: 1px solid rgba(13, 21, 71, 0.08);
  background: rgba(255, 255, 255, 0.72);
}

.review-foot-hint {
  font-size: 11.5px;
  color: var(--lnu-text-muted);
}

.review-session-foot .primary-btn {
  height: 34px;
}

/* ---- Responsive ----
   Below 1000px the two panels can't both hold a usable width, so the page
   gives up the fixed-height treatment entirely: panels stack, every inner
   scroll is released, and .layout-content's own overflow takes over. Fixed
   viewport panes on a narrow screen are worse than a normal scrolling page. */
@media (max-width: 1000px) {
  .review-bot-view {
    flex: none;
  }

  /* minmax(0, ...), not a bare 1fr: a bare one cannot be narrower than the
     longest one-line text inside it, and the review panel's subject line
     pushed both panels 5px past the edge of a 320px phone. */
  .review-grid {
    grid-template-columns: minmax(0, 1fr);
    flex: none;
    min-height: 0;
  }

  .review-panel {
    min-height: 0;
  }

  .review-setup-body,
  .review-question-scroll,
  .review-chip-list {
    overflow: visible;
    max-height: none;
  }

  .review-question-scroll {
    flex: none;
  }

  .review-empty {
    min-height: 220px;
  }

  .review-session {
    scroll-margin-top: 10px;
  }

  /*
   * Each panel's action bar stays at the bottom of the screen while that
   * panel scrolls past: Start Review once a subject's topics have pushed it
   * down, then how many are left and Submit instead of waiting under the
   * last question. clip keeps the rounded corners without making the panel
   * the box the bar sticks to, which hidden would.
   */
  .review-setup,
  .review-session {
    overflow: clip;
  }

  .review-setup-foot,
  .review-session-foot {
    position: sticky;
    bottom: 0;
    z-index: 2;
    box-shadow: 0 -6px 16px rgba(13, 21, 71, 0.06);
  }

  .review-setup-foot {
    background: #fff;
  }

  .review-session-foot {
    background: #fff;
  }
}

@media (max-width: 640px) {
  .review-panel-head,
  .review-setup-body,
  .review-question-scroll {
    padding-left: 13px;
    padding-right: 13px;
  }

  .review-result {
    grid-template-columns: 1fr;
  }

  /* Read at arm's length on a phone, so the size of the exam's own text. */
  .review-question-text {
    font-size: 16px;
    line-height: 1.5;
  }

  .review-option {
    font-size: 15px;
  }

  .review-explanation strong {
    font-size: 13.5px;
  }

  .review-explanation p {
    font-size: 13px;
  }

  .review-result-score {
    justify-items: start;
    padding-right: 0;
    padding-bottom: 11px;
    border-right: none;
    border-bottom: 1px solid rgba(13, 21, 71, 0.1);
  }

  .review-session-foot {
    padding-left: 13px;
    padding-right: 13px;
  }
}

@media (pointer: coarse) {
  .review-segment {
    height: 40px;
    font-size: 13px;
  }

  .review-subject-chip {
    min-height: 44px;
    font-size: 13px;
  }

  .review-chip {
    min-height: 40px;
    padding: 8px 14px;
    font-size: 13px;
  }

  .review-chip.is-numeric {
    min-width: 48px;
  }

  .review-start-btn,
  .review-clear-btn,
  .review-session-foot .primary-btn {
    height: 44px;
  }

  .review-result-actions .primary-btn,
  .review-result-actions .ghost-btn {
    height: 44px;
    font-size: 13px;
  }

  /* A text link 16px tall; the padding is its tap area. */
  .review-clear-link {
    min-height: 44px;
    margin-block: -14px;
    padding-inline: 6px;
    font-size: 12px;
  }

  /* An answer is the whole row, not the 13px dot at its start. */
  .review-option {
    align-items: center;
    min-height: 48px;
    padding: 11px 12px;
  }

  .review-option input {
    width: 20px;
    height: 20px;
    margin-top: 0;
  }
}
</style>
