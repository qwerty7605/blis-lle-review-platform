<template>
  <section class="review-bot-view">
    <div v-if="reviewMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ reviewMessage }}</span>
    </div>
    <div v-if="reviewError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ reviewError }}</span>
    </div>

    <article class="surface-card review-bot-hero">
      <div class="review-bot-hero-copy">
        <span class="review-bot-kicker">Weak-Area Practice</span>
        <h3>Practice the concepts you missed most often</h3>
        <p>
          The system now serves approved AI-assisted remedial questions from your teacher library.
          No live question generation happens when students practice.
        </p>
      </div>

      <div class="review-bot-hero-meta">
        <span class="pill success">{{ weakAreaBasisLabel }}</span>
        <span class="pill neutral">{{ availableQuestions }} approved question(s)</span>
      </div>
    </article>

    <div class="review-bot-layout">
      <article class="surface-card review-bot-setup">
        <header class="surface-head">
          <h3>Recommended Focus</h3>
          <span class="pill navy">{{ form.questionCount }} questions</span>
        </header>

        <section class="review-bot-section">
          <div v-if="overviewLoading" class="review-bot-empty compact">
            <RefreshCw :size="18" class="spin-soft" />
            <p>Calculating your weak-area recommendation...</p>
          </div>

          <div v-else-if="!weakArea" class="review-bot-empty compact">
            <BookOpen :size="20" />
            <p>Finish more official exams first so the system can map your weak area.</p>
          </div>

          <div v-else class="review-focus-card">
            <div class="review-focus-icon">
              <Target :size="18" />
            </div>
            <div>
              <strong>{{ weakAreaLabel }}</strong>
              <p>
                {{
                  weakArea?.basis === 'topic'
                    ? 'Topic-level weakness detected from your submitted exam answers.'
                    : 'Topic tags are unavailable, so practice falls back to your weakest subject.'
                }}
              </p>
            </div>
          </div>
        </section>

        <section class="review-bot-section">
          <label class="field-stack">
            <span class="field-label">How many questions do you want to answer?</span>
            <input v-model.number="form.questionCount" type="number" min="3" max="30" class="text-input" />
            <small class="muted">Choose between 3 and 30 questions.</small>
          </label>

          <div class="review-count-chips">
            <button
              v-for="count in questionCountOptions"
              :key="count"
              type="button"
              class="review-count-chip"
              :class="{ active: form.questionCount === count }"
              @click="setQuestionCount(count)"
            >
              {{ count }} items
            </button>
          </div>
        </section>

        <div class="review-bot-actions">
          <button
            type="button"
            class="primary-btn"
            :disabled="quizLoading || overviewLoading || !canGenerate"
            @click="generateQuiz"
          >
            <Sparkles :size="16" />
            {{ quizLoading ? 'Starting...' : 'Start Recommended Practice' }}
          </button>
          <button v-if="hasQuiz" type="button" class="ghost-btn" :disabled="quizLoading" @click="resetQuizState">
            Clear Set
          </button>
        </div>
      </article>

      <article class="surface-card review-bot-session">
        <header class="surface-head">
          <h3>{{ hasQuiz ? 'Practice Set' : 'Recommended Session' }}</h3>
          <span v-if="hasQuiz" class="pill neutral">{{ answeredCount }}/{{ generatedQuestions.length }} answered</span>
        </header>

        <div v-if="quizLoading" class="review-bot-empty">
          <RefreshCw :size="22" class="spin-soft" />
          <h4>Loading your remedial set</h4>
          <p>The system is pulling approved questions for your current weak area.</p>
        </div>

        <div v-else-if="!hasQuiz" class="review-bot-empty">
          <Sparkles :size="26" />
          <h4>Your practice set will appear here</h4>
          <p>Start a recommended session to load approved remedial questions.</p>
        </div>

        <template v-else>
          <div v-if="attemptSubmitted" class="review-bot-results">
            <div class="review-bot-score" :class="{ strong: scorePercent >= 75, focus: scorePercent < 75 }">
              <strong>{{ scorePercent }}%</strong>
              <span>{{ correctCount }} / {{ generatedQuestions.length }} correct</span>
            </div>

            <button type="button" class="ghost-btn" :disabled="quizLoading" @click="generateQuiz">
              <RefreshCw :size="15" />
              Start Another Set
            </button>
          </div>

          <div class="review-question-list">
            <article v-for="(question, index) in generatedQuestions" :key="question.id" class="review-question-card">
              <div class="review-question-head">
                <span class="review-question-number">Question {{ index + 1 }}</span>
                <div class="review-question-tags">
                  <span class="pill neutral">{{ question.subject }}</span>
                  <span v-if="question.topic" class="pill navy">{{ question.topic }}</span>
                </div>
              </div>

              <p class="review-question-text">{{ question.prompt }}</p>

              <div class="review-option-list">
                <label
                  v-for="option in question.options"
                  :key="option.id"
                  class="review-option"
                  :class="{
                    'is-selected': answers[question.id] === option.id,
                    'is-correct': questionFeedbackVisible(question) && option.is_correct === true,
                    'is-incorrect': questionFeedbackVisible(question) && answers[question.id] === option.id && option.is_correct !== true,
                  }"
                >
                  <input
                    :checked="answers[question.id] === option.id"
                    type="radio"
                    :name="`review-question-${question.id}`"
                    :value="option.id"
                    :disabled="attemptSubmitted"
                    @change="answerQuestion(question.id, option.id)"
                  />
                  <span>{{ option.label ? `${option.label}. ` : '' }}{{ option.text }}</span>
                </label>
              </div>

              <div v-if="questionFeedbackVisible(question)" class="review-explanation">
                <strong>{{ question.answer?.is_correct ? 'Correct' : 'Needs review' }}</strong>
                <p>
                  Correct answer:
                  {{ question.correctAnswer?.label || '' }}<span v-if="question.correctAnswer?.text">. {{ question.correctAnswer.text }}</span>
                </p>
              </div>
            </article>
          </div>

          <footer class="review-bot-footer">
            <p>
              {{
                attemptSubmitted
                  ? 'Review the correct answers, then start another set when you are ready.'
                  : 'Each answered item reveals the correct answer right away. Submit when you want your full score summary.'
              }}
            </p>

            <button
              v-if="!attemptSubmitted"
              type="button"
              class="primary-btn"
              :disabled="quizLoading || !allAnswered"
              @click="submitQuiz"
            >
              Submit Practice Set
            </button>
            <button v-else type="button" class="ghost-btn" @click="resetQuizState">
              Start Fresh
            </button>
          </footer>
        </template>
      </article>
    </div>
  </section>
</template>

<script setup>
import { AlertCircle, BookOpen, CheckCircle2, RefreshCw, Sparkles, Target } from 'lucide-vue-next'
import { useReviewBotModule } from '../composables/useReviewBotModule'

const {
  availableQuestions,
  answeredCount,
  allAnswered,
  answerQuestion,
  attemptSubmitted,
  canGenerate,
  correctCount,
  form,
  generateQuiz,
  generatedQuestions,
  hasQuiz,
  overviewLoading,
  questionCountOptions,
  questionFeedbackVisible,
  quizLoading,
  resetQuizState,
  reviewError,
  reviewMessage,
  scorePercent,
  setQuestionCount,
  submitQuiz,
  weakArea,
  weakAreaBasisLabel,
  weakAreaLabel,
  answers,
} = useReviewBotModule()
</script>

<style scoped src="../dashboard.css"></style>
<style scoped>
.review-bot-view {
  display: grid;
  gap: 20px;
}

.review-bot-hero {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  align-items: flex-start;
  background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
  color: #fff;
}

.review-bot-hero-copy {
  max-width: 720px;
}

.review-bot-kicker {
  display: inline-block;
  font-size: 0.8rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #bfdbfe;
  margin-bottom: 10px;
}

.review-bot-hero-copy h3 {
  margin: 0 0 10px;
  font-size: 1.7rem;
}

.review-bot-hero-copy p {
  margin: 0;
  color: rgba(255, 255, 255, 0.82);
  line-height: 1.6;
}

.review-bot-hero-meta {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.review-bot-layout {
  display: grid;
  grid-template-columns: minmax(300px, 360px) minmax(0, 1fr);
  gap: 20px;
}

.review-bot-section {
  display: grid;
  gap: 14px;
}

.review-bot-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.review-count-chips {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.review-count-chip {
  border: 1px solid #cbd5e1;
  background: #f8fafc;
  color: #0f172a;
  border-radius: 999px;
  padding: 8px 14px;
  cursor: pointer;
}

.review-count-chip.active {
  background: #1d4ed8;
  border-color: #1d4ed8;
  color: #fff;
}

.review-bot-empty {
  display: grid;
  justify-items: center;
  gap: 10px;
  text-align: center;
  padding: 32px 20px;
  color: #64748b;
}

.review-bot-empty.compact {
  padding: 18px;
  border: 1px dashed #cbd5e1;
  border-radius: 14px;
}

.review-focus-card {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  padding: 16px;
  border-radius: 16px;
  background: linear-gradient(180deg, #eff6ff 0%, #f8fafc 100%);
  border: 1px solid #bfdbfe;
}

.review-focus-card p {
  margin: 4px 0 0;
  color: #475569;
}

.review-focus-icon {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  border-radius: 12px;
  background: #dbeafe;
  color: #1d4ed8;
  flex: 0 0 auto;
}

.review-bot-results,
.review-bot-footer,
.review-question-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
}

.review-bot-score {
  display: grid;
  gap: 4px;
}

.review-bot-score strong {
  font-size: 1.7rem;
}

.review-bot-score.strong strong {
  color: #15803d;
}

.review-bot-score.focus strong {
  color: #b45309;
}

.review-question-list {
  display: grid;
  gap: 16px;
}

.review-question-card {
  padding: 18px;
  border: 1px solid #e2e8f0;
  border-radius: 18px;
  background: #fff;
  display: grid;
  gap: 14px;
}

.review-question-number {
  font-weight: 700;
  color: #0f172a;
}

.review-question-tags {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.review-question-text {
  margin: 0;
  line-height: 1.6;
  color: #0f172a;
}

.review-option-list {
  display: grid;
  gap: 10px;
}

.review-option {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 12px 14px;
  border-radius: 14px;
  border: 1px solid #cbd5e1;
  background: #f8fafc;
  cursor: pointer;
}

.review-option.is-selected {
  border-color: #1d4ed8;
  background: #eff6ff;
}

.review-option.is-correct {
  border-color: #15803d;
  background: #f0fdf4;
}

.review-option.is-incorrect {
  border-color: #b91c1c;
  background: #fef2f2;
}

.review-option input {
  margin-top: 2px;
}

.review-explanation {
  display: grid;
  gap: 4px;
  padding: 12px 14px;
  border-radius: 12px;
  background: #f8fafc;
  color: #475569;
}

.review-explanation p {
  margin: 0;
}

.spin-soft {
  animation: spin-soft 1s linear infinite;
}

@keyframes spin-soft {
  from {
    transform: rotate(0deg);
  }

  to {
    transform: rotate(360deg);
  }
}

@media (max-width: 980px) {
  .review-bot-layout {
    grid-template-columns: 1fr;
  }

  .review-bot-hero,
  .review-bot-results,
  .review-bot-footer,
  .review-question-head {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
