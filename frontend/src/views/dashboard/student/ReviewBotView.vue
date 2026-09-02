<template>
  <section class="review-bot-view">
    <div v-if="sessionError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ sessionError }}</span>
    </div>

    <div v-if="catalogError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ catalogError }}</span>
    </div>

    <article class="surface-card review-bot-head">
      <p class="review-bot-kicker">Review Bot</p>
      <h3>BLIS Review Bot</h3>
      <p>Practice questions your teachers have already generated and published — no waiting on AI.</p>
    </article>

    <div class="review-bot-layout">
      <article class="surface-card review-bot-setup">
        <header class="review-section-head">
          <h3>Setup</h3>
          <span class="pill navy">{{ form.questionCount }} items</span>
        </header>

        <section class="review-field">
          <div class="review-field-head">
            <strong>Mode</strong>
          </div>

          <div class="review-count-chips">
            <button
              type="button"
              class="review-count-chip"
              :class="{ active: mode === 'manual' }"
              @click="setMode('manual')"
            >
              Choose Subject/Topic
            </button>
            <button
              type="button"
              class="review-count-chip"
              :class="{ active: mode === 'weak_area' }"
              :disabled="!weakArea"
              @click="setMode('weak_area')"
            >
              <Target :size="13" /> Review My Weak Areas
            </button>
          </div>

          <div v-if="mode === 'weak_area'" class="review-weak-area-box">
            <template v-if="weakArea">
              <p class="review-muted">We detected that you need more practice in:</p>
              <strong class="review-weak-area-label">{{ weakAreaLabel }}</strong>
              <p v-if="weakAreaScoreText" class="review-muted">Your previous performance: {{ weakAreaScoreText }}</p>
            </template>
            <p v-else class="review-muted">
              Take and submit a few more exams first — we need some history to detect a weak area.
            </p>
          </div>
        </section>

        <template v-if="mode === 'manual'">
          <section class="review-field">
            <div class="review-field-head">
              <strong>Subject</strong>
            </div>

            <div v-if="catalogLoading" class="review-bot-empty compact">
              <RefreshCw :size="18" class="spin-soft" />
              <p>Loading subjects...</p>
            </div>

            <div v-else-if="readySubjects.length === 0" class="review-bot-empty compact">
              <BookOpen :size="20" />
              <p>No published Review Bot questions yet. Check back after your teacher publishes some.</p>
            </div>

            <div v-else class="review-subject-grid">
              <button
                v-for="subject in readySubjects"
                :key="subject.subject"
                type="button"
                class="review-subject-chip"
                :class="[`tone-${subjectToneKey(subject.subject)}`, { 'is-selected': form.subject === subject.subject }]"
                @click="selectSubject(subject.subject)"
              >
                {{ shortSubjectLabel(subject.subject) }}
              </button>
            </div>
          </section>

          <section v-if="availableTopics.length > 0" class="review-field">
            <div class="review-field-head">
              <strong>Topic</strong>
            </div>

            <div class="review-subject-grid">
              <button
                v-for="topic in availableTopics"
                :key="topic.id"
                type="button"
                class="review-subject-chip"
                :class="{ 'is-selected': form.topicId === topic.id }"
                @click="selectTopic(form.topicId === topic.id ? null : topic.id)"
              >
                {{ topic.name }}
              </button>
            </div>
          </section>

          <section v-if="availableSubtopics.length > 0" class="review-field">
            <div class="review-field-head">
              <strong>Subtopic</strong>
            </div>

            <div class="review-subject-grid">
              <button
                v-for="subtopic in availableSubtopics"
                :key="subtopic.id"
                type="button"
                class="review-subject-chip"
                :class="{ 'is-selected': form.subtopicId === subtopic.id }"
                @click="selectSubtopic(form.subtopicId === subtopic.id ? null : subtopic.id)"
              >
                {{ subtopic.name }}
              </button>
            </div>
          </section>

          <section v-if="availableDifficulties.length > 0" class="review-field">
            <div class="review-field-head">
              <strong>Difficulty</strong>
            </div>

            <div class="review-count-chips">
              <button
                type="button"
                class="review-count-chip"
                :class="{ active: !form.difficulty }"
                @click="setDifficulty('')"
              >
                Any
              </button>
              <button
                v-for="difficulty in availableDifficulties"
                :key="difficulty"
                type="button"
                class="review-count-chip"
                :class="{ active: form.difficulty === difficulty }"
                @click="setDifficulty(difficulty)"
              >
                {{ difficulty }}
              </button>
            </div>
          </section>
        </template>

        <section class="review-field">
          <div class="review-field-head">
            <strong>Items</strong>
          </div>

          <div class="review-count-row">
            <div class="review-count-chips">
              <button
                v-for="count in questionCountOptions"
                :key="count"
                type="button"
                class="review-count-chip"
                :class="{ active: form.questionCount === count }"
                @click="setQuestionCount(count)"
              >
                {{ count }}
              </button>
            </div>
          </div>
        </section>

        <div class="review-bot-actions">
          <button
            type="button"
            class="primary-btn"
            :disabled="sessionStarting || !canStart"
            @click="startSession"
          >
            <Sparkles :size="16" />
            {{ sessionStarting ? 'Starting...' : 'Start Review' }}
          </button>

          <button v-if="hasSession" type="button" class="ghost-btn" :disabled="sessionStarting" @click="startAnother">
            Clear
          </button>
        </div>
      </article>

      <article class="surface-card review-bot-session">
        <header class="review-section-head">
          <h3>Review</h3>

          <div v-if="hasSession" class="review-session-pills">
            <span class="pill neutral">{{ session.subject }}</span>
            <span v-if="!result" class="pill navy">{{ answeredCount }}/{{ session.questions.length }} answered</span>
          </div>
        </header>

        <div class="review-session-body">
          <div v-if="hasSession && session.scopeNote && !result" class="feedback info">
            <Info :size="15" />
            <span>{{ session.scopeNote }}</span>
          </div>

          <div v-if="sessionStarting" class="review-bot-empty">
            <RefreshCw :size="22" class="spin-soft" />
            <h4>Preparing your set</h4>
            <p>Please wait.</p>
          </div>

          <div v-else-if="!hasSession" class="review-bot-empty">
            <Sparkles :size="24" />
            <h4>No review session yet</h4>
            <p>Choose a subject (or use your weak areas) and start a review.</p>
          </div>

          <template v-else>
            <div v-if="result" class="review-session-top">
              <div class="review-bot-results">
                <div class="review-bot-score" :class="scoreToneClass(result.scorePercent)">
                  <span class="review-score-kicker">Score</span>
                  <strong>{{ result.scorePercent }}%</strong>
                  <span>{{ result.correctAnswers }} / {{ result.totalItems }} correct</span>
                  <span v-if="result.improved !== null" class="review-improved" :class="{ down: result.improved === false }">
                    {{ result.improved ? '▲ Improved from last time' : '▼ Lower than last time' }}
                  </span>
                </div>

                <div class="review-recommendation" v-if="result.recommendation">
                  <strong>{{ recommendationHeading(result.recommendation.action) }}</strong>
                  <p>{{ result.recommendation.message }}</p>
                  <div class="review-recommendation-actions">
                    <button
                      v-if="result.recommendation.action === 'repeat_topic'"
                      type="button"
                      class="primary-btn"
                      @click="repeatSameTopic"
                    >
                      Review This Topic Again
                    </button>
                    <button
                      v-else-if="result.recommendation.action === 'next_topic'"
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
            </div>

            <div v-else class="review-session-top">
              <div class="review-session-status">
                <span class="review-session-label">Progress</span>
                <strong>{{ answeredCount }} of {{ session.questions.length }}</strong>
                <span>Submit anytime</span>
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

            <footer v-if="!result" class="review-bot-footer">
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
      </article>
    </div>
  </section>
</template>

<script setup>
import { AlertCircle, BookOpen, Info, RefreshCw, Sparkles, Target } from 'lucide-vue-next'
import { useReviewBotModule } from '../composables/useReviewBotModule'

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

const subjectToneLookup = {
  'Cataloging and Classification': 'cataloging',
  'Indexing and Abstracting': 'indexing',
  'Information Technology': 'technology',
  'Reference Services': 'reference',
  'Library Management': 'management',
  'Selection and Acquisition': 'acquisition',
}

const shortSubjectLookup = {
  'Cataloging and Classification': 'Cataloging',
  'Indexing and Abstracting': 'Indexing',
  'Information Technology': 'IT',
  'Reference Services': 'Reference',
  'Library Management': 'Management',
  'Selection and Acquisition': 'Acquisition',
}

function subjectToneKey(subject) {
  return subjectToneLookup[subject] ?? 'cataloging'
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
.review-bot-view {
  display: grid;
  gap: 16px;
}

.review-bot-head {
  display: grid;
  gap: 8px;
  background: #f4f7ff;
  border-color: rgba(31, 65, 143, 0.18);
}

.review-bot-kicker {
  margin: 0;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #234a9c;
}

.review-bot-head h3 {
  margin: 0;
  color: var(--lnu-navy-deep);
  font-size: clamp(26px, 4vw, 34px);
  line-height: 1.08;
}

.review-bot-head p {
  margin: 0;
  color: var(--lnu-text-muted);
  line-height: 1.55;
}

.review-bot-layout {
  display: grid;
  grid-template-columns: minmax(320px, 390px) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.review-bot-setup,
.review-bot-session {
  display: grid;
  gap: 14px;
  padding: 18px;
}

.review-bot-setup {
  background: #fffaf0;
}

.review-bot-session {
  background: #fcfdff;
  grid-template-rows: auto minmax(0, 1fr);
  height: min(760px, calc(100vh - 220px));
}

.review-section-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.review-section-head h3 {
  margin: 0;
  color: var(--lnu-navy-deep);
}

.review-field {
  display: grid;
  gap: 10px;
  padding: 14px;
  border-radius: 16px;
  border: 1px solid rgba(13, 21, 71, 0.1);
  background: #ffffff;
}

.review-field-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.review-field-head strong {
  color: var(--lnu-navy-deep);
  font-size: 15px;
}

.review-weak-area-box {
  display: grid;
  gap: 4px;
  padding: 12px;
  border-radius: 14px;
  background: #eef3ff;
  border: 1px solid rgba(31, 65, 143, 0.14);
}

.review-weak-area-label {
  color: var(--lnu-navy-deep);
  font-size: 15px;
}

.review-subject-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.review-subject-chip {
  border-radius: 14px;
  padding: 12px;
  font-size: 13px;
  font-weight: 700;
  text-align: center;
  border: 1px solid rgba(13, 21, 71, 0.12);
  transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
}

.review-subject-chip:hover {
  transform: translateY(-1px);
}

.review-subject-chip.is-selected {
  border-color: rgba(31, 65, 143, 0.28);
  box-shadow: 0 10px 18px rgba(16, 32, 73, 0.08);
}

.review-count-chip svg,
.pill svg {
  vertical-align: -2px;
}

.review-count-row {
  display: grid;
  gap: 12px;
}

.review-count-chips,
.review-session-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.review-count-chip {
  border: 1px solid rgba(23, 49, 108, 0.12);
  border-radius: 999px;
  background: #ffffff;
  color: var(--lnu-text);
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 700;
  text-transform: capitalize;
}

.review-count-chip.active,
.review-count-chip:hover {
  border-color: rgba(49, 92, 191, 0.2);
  background: #dfe9ff;
  color: #20428f;
}

.review-count-chip:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.review-muted {
  margin: 0;
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.review-bot-actions,
.review-bot-results,
.review-bot-footer,
.review-recommendation-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.review-bot-results {
  flex-direction: column;
  align-items: stretch;
}

.review-bot-footer {
  justify-content: flex-end;
  padding-top: 8px;
  border-top: 1px solid rgba(13, 21, 71, 0.08);
}

.review-session-body {
  min-height: 0;
  display: grid;
  grid-template-rows: min-content minmax(0, 1fr) auto;
  gap: 12px;
}

.review-session-body > .review-bot-empty {
  grid-row: 1 / -1;
}

.review-session-top {
  display: grid;
  gap: 12px;
}

.review-session-status {
  display: grid;
  gap: 3px;
  padding: 14px 16px;
  border-radius: 16px;
  border: 1px solid rgba(31, 65, 143, 0.12);
  background: #eef3ff;
}

.review-session-label {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(16, 32, 73, 0.58);
}

.review-session-status strong {
  color: var(--lnu-navy-deep);
  font-size: 20px;
  line-height: 1.1;
}

.review-session-status span:last-child {
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.review-bot-empty {
  min-height: 250px;
  height: 100%;
  display: grid;
  place-items: center;
  text-align: center;
  gap: 8px;
  border: 1px dashed rgba(13, 21, 71, 0.18);
  border-radius: 18px;
  background: #f6f8fd;
  padding: 18px;
  color: var(--lnu-text-muted);
}

.review-bot-empty.compact {
  min-height: 110px;
}

.review-bot-empty h4,
.review-bot-empty p {
  margin: 0;
}

.review-bot-score {
  display: grid;
  gap: 4px;
  padding: 16px 18px;
  border-radius: 18px;
  border: 1px solid rgba(13, 21, 71, 0.1);
}

.review-bot-score.strong {
  background: #ddf3e5;
  border-color: rgba(63, 146, 94, 0.2);
}

.review-bot-score.steady {
  background: #dfe9ff;
  border-color: rgba(49, 92, 191, 0.18);
}

.review-bot-score.focus {
  background: #ffefc4;
  border-color: rgba(205, 151, 43, 0.22);
}

.review-score-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: rgba(16, 32, 73, 0.68);
}

.review-bot-score strong {
  font-size: 34px;
  line-height: 1;
  color: var(--lnu-navy-deep);
}

.review-bot-score span {
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.review-improved {
  font-weight: 700;
  color: #267242;
}

.review-improved.down {
  color: #ad5340;
}

.review-recommendation {
  display: grid;
  gap: 8px;
  padding: 14px 16px;
  border-radius: 16px;
  background: #f6f8fd;
  border: 1px solid rgba(13, 21, 71, 0.1);
}

.review-recommendation strong {
  color: var(--lnu-navy-deep);
}

.review-recommendation p {
  margin: 0;
  color: var(--lnu-text-muted);
  font-size: 14px;
}

.review-question-scroll {
  min-height: 0;
  overflow-y: auto;
  padding-right: 6px;
  scrollbar-gutter: stable;
}

.review-question-list {
  display: grid;
  gap: 14px;
}

.review-question-card {
  display: grid;
  gap: 12px;
  padding: 16px;
  border-radius: 18px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  background: #ffffff;
}

.review-question-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.review-question-number {
  display: block;
  color: var(--lnu-text-muted);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.review-question-text {
  margin: 0;
  color: var(--lnu-text);
  font-size: 17px;
  line-height: 1.55;
}

.review-option-list {
  display: grid;
  gap: 8px;
}

.review-option {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 11px 12px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 13px;
  background: rgba(255, 255, 255, 0.92);
  color: var(--lnu-text);
}

.review-option.is-selected {
  border-color: rgba(31, 65, 143, 0.24);
  background: #ebf1ff;
}

.review-option input {
  margin-top: 2px;
  accent-color: var(--lnu-navy);
}

.review-explanation {
  display: grid;
  gap: 4px;
  padding: 12px 14px;
  border-radius: 14px;
  background: #f6f8fd;
  border: 1px solid rgba(13, 21, 71, 0.1);
}

.review-explanation strong,
.review-explanation p {
  margin: 0;
}

.review-explanation p {
  color: var(--lnu-text-muted);
  font-size: 14px;
  line-height: 1.5;
}

.tone-cataloging {
  background: #e4edff;
  color: #234a9c;
}

.tone-indexing {
  background: #def3f6;
  color: #1c7286;
}

.tone-technology {
  background: #fff0ca;
  color: #966112;
}

.tone-reference {
  background: #ffe6e1;
  color: #ad5340;
}

.tone-management {
  background: #e2f4e7;
  color: #267242;
}

.tone-acquisition {
  background: #f7ead8;
  color: #83551e;
}

@media (max-width: 1100px) {
  .review-bot-layout {
    grid-template-columns: 1fr;
  }

  .review-bot-session {
    height: auto;
  }

  .review-session-body {
    grid-template-rows: auto;
  }

  .review-question-scroll {
    overflow: visible;
    padding-right: 0;
  }
}

@media (max-width: 720px) {
  .review-bot-head,
  .review-bot-setup,
  .review-bot-session,
  .review-question-card {
    padding: 14px;
  }

  .review-section-head,
  .review-field-head,
  .review-question-head {
    flex-direction: column;
    align-items: flex-start;
  }

  .review-subject-grid {
    grid-template-columns: 1fr;
  }

  .review-bot-actions,
  .review-bot-footer {
    width: 100%;
  }

  .review-bot-actions .primary-btn,
  .review-bot-actions .ghost-btn,
  .review-bot-footer .primary-btn,
  .review-bot-footer .ghost-btn {
    width: 100%;
    justify-content: center;
  }
}
</style>
