<template>
  <section class="rbg-view">
    <article class="surface-card rbg-head">
      <p class="rbg-kicker">Review Bot Question Generator</p>
      <h3>Generate, review, and publish Review Bot questions</h3>
      <p>
        Pick a subject/topic/subtopic, generate a batch grounded in your uploaded library content, then
        review and edit before publishing. Students only ever see published questions here — generating
        never happens on their side.
      </p>
    </article>

    <div v-if="catalogError" class="feedback danger"><AlertCircle :size="15" /><span>{{ catalogError }}</span></div>
    <div v-if="generateError" class="feedback danger"><AlertCircle :size="15" /><span>{{ generateError }}</span></div>
    <div v-if="generateMessage" class="feedback success"><CheckCircle2 :size="15" /><span>{{ generateMessage }}</span></div>
    <div v-if="questionsError" class="feedback danger"><AlertCircle :size="15" /><span>{{ questionsError }}</span></div>

    <article class="surface-card rbg-generate">
      <header class="review-section-head">
        <h3>Generate</h3>
      </header>

      <div class="rbg-generate-grid">
        <label class="field-stack">
          <span class="field-label">Subject</span>
          <select class="text-input" :value="form.subject" @change="selectSubject($event.target.value)">
            <option v-for="subject in subjectOptions" :key="subject" :value="subject">{{ subject }}</option>
          </select>
        </label>

        <label class="field-stack">
          <span class="field-label">Topic (optional)</span>
          <select class="text-input" :value="form.topic" @change="selectTopic($event.target.value)">
            <option value="">Any topic</option>
            <option v-for="topic in topicOptions" :key="topic" :value="topic">{{ topic }}</option>
          </select>
        </label>

        <label class="field-stack">
          <span class="field-label">Subtopic (optional)</span>
          <select class="text-input" v-model="form.subtopic" :disabled="!form.topic">
            <option value="">Any subtopic</option>
            <option v-for="subtopic in subtopicOptions" :key="subtopic" :value="subtopic">{{ subtopic }}</option>
          </select>
        </label>

        <label class="field-stack">
          <span class="field-label">Source items to cover</span>
          <input v-model.number="form.sourceLimit" type="number" min="1" max="50" class="text-input" />
          <span class="field-hint">Each exam item can yield up to one review question per cognitive level, so this covers more questions than the number alone suggests.</span>
        </label>
      </div>

      <div class="rbg-generate-actions">
        <button type="button" class="primary-btn" :disabled="generating" @click="generate">
          <Sparkles :size="16" />
          {{ generating ? 'Queuing...' : 'Generate' }}
        </button>
        <p class="review-muted">Runs in the background (AI-grounded on your uploaded library content) — refresh the list below in a few seconds.</p>
      </div>
    </article>

    <article class="surface-card rbg-list">
      <header class="review-section-head">
        <h3>Questions</h3>

        <div class="rbg-list-actions">
          <div class="review-count-chips">
            <button type="button" class="review-count-chip" :class="{ active: statusFilter === 'draft' }" @click="statusFilter = 'draft'">Draft</button>
            <button type="button" class="review-count-chip" :class="{ active: statusFilter === 'published' }" @click="statusFilter = 'published'">Published</button>
            <button type="button" class="review-count-chip" :class="{ active: statusFilter === 'all' }" @click="statusFilter = 'all'">All</button>
          </div>

          <button type="button" class="ghost-btn" :disabled="questionsLoading" @click="loadQuestions">
            <RefreshCw :size="14" :class="{ 'spin-soft': questionsLoading }" /> Refresh
          </button>
        </div>
      </header>

      <div v-if="filteredQuestions.length > 0" class="rbg-bulk-bar">
        <label class="rbg-select-all">
          <input type="checkbox" :checked="allFilteredSelected" @change="toggleSelectAllFiltered" />
          <span>Select all ({{ filteredQuestions.length }})</span>
        </label>

        <div class="rbg-bulk-actions">
          <button type="button" class="primary-btn" :disabled="selectedIds.length === 0" @click="publishSelected">
            Publish Selected ({{ selectedIds.length }})
          </button>
          <button type="button" class="ghost-btn" :disabled="selectedIds.length === 0" @click="unpublishSelected">
            Unpublish Selected
          </button>
        </div>
      </div>

      <div v-if="questionsLoading" class="review-bot-empty compact">
        <RefreshCw :size="18" class="spin-soft" />
        <p>Loading questions...</p>
      </div>

      <div v-else-if="filteredQuestions.length === 0" class="review-bot-empty compact">
        <BookOpen :size="20" />
        <p>No {{ statusFilter === 'all' ? '' : statusFilter }} questions yet.</p>
      </div>

      <div v-else class="rbg-question-list">
        <article v-for="question in filteredQuestions" :key="question.id" class="rbg-question-card">
          <div class="rbg-question-head">
            <label class="rbg-select-one">
              <input type="checkbox" :checked="selectedIds.includes(question.id)" @change="toggleSelected(question.id)" />
            </label>

            <div class="rbg-question-meta">
              <span class="pill" :class="question.status === 'published' ? 'success' : 'gold'">{{ question.status }}</span>
              <span class="pill neutral">{{ question.subject }}</span>
              <span v-if="question.topic" class="pill neutral">{{ question.topic }}</span>
              <span v-if="question.subtopic" class="pill neutral">{{ question.subtopic }}</span>
              <span class="pill navy">{{ question.difficulty }}</span>
            </div>

            <div class="rbg-question-actions">
              <button v-if="!editing[question.id]" type="button" class="ghost-btn review-small-btn" @click="startEditing(question)">Edit</button>
              <button type="button" class="ghost-btn review-small-btn danger" @click="deleteQuestion(question.id)">Delete</button>
            </div>
          </div>

          <template v-if="editing[question.id]">
            <label class="field-stack">
              <span class="field-label">Question</span>
              <textarea v-model="editing[question.id].questionText" class="text-input" rows="2"></textarea>
            </label>

            <div class="rbg-edit-options">
              <label v-for="option in editing[question.id].options" :key="option.id" class="rbg-edit-option">
                <input
                  type="radio"
                  :name="`rbg-correct-${question.id}`"
                  :checked="option.isCorrect"
                  @change="setEditingCorrectOption(question.id, option.id)"
                />
                <input v-model="option.text" class="text-input" />
              </label>
            </div>

            <label class="field-stack">
              <span class="field-label">Explanation</span>
              <textarea v-model="editing[question.id].explanation" class="text-input" rows="2"></textarea>
            </label>

            <label class="field-stack">
              <span class="field-label">Difficulty</span>
              <select v-model="editing[question.id].difficulty" class="text-input">
                <option value="easy">Easy</option>
                <option value="medium">Medium</option>
                <option value="hard">Hard</option>
              </select>
            </label>

            <div class="rbg-edit-actions">
              <button type="button" class="primary-btn" :disabled="savingQuestionId === question.id" @click="saveEditing(question.id)">
                {{ savingQuestionId === question.id ? 'Saving...' : 'Save' }}
              </button>
              <button type="button" class="ghost-btn" @click="cancelEditing(question.id)">Cancel</button>
            </div>
          </template>

          <template v-else>
            <p class="rbg-question-text">{{ question.questionText }}</p>

            <ul class="rbg-option-list">
              <li v-for="option in question.options" :key="option.id" :class="{ 'is-correct': option.isCorrect }">
                {{ option.label }}. {{ option.text }}
              </li>
            </ul>

            <p v-if="question.explanation" class="rbg-explanation">{{ question.explanation }}</p>
            <p v-if="question.sourceReference" class="review-muted">{{ question.sourceReference }}</p>
          </template>
        </article>
      </div>
    </article>
  </section>
</template>

<script setup>
import { AlertCircle, BookOpen, CheckCircle2, RefreshCw, Sparkles } from 'lucide-vue-next'
import { useReviewBotAdminModule } from '../composables/useReviewBotAdminModule'

const {
  catalogError,
  form,
  subjectOptions,
  topicOptions,
  subtopicOptions,
  generating,
  generateError,
  generateMessage,
  filteredQuestions,
  questionsLoading,
  questionsError,
  statusFilter,
  selectedIds,
  allFilteredSelected,
  editing,
  savingQuestionId,
  selectSubject,
  selectTopic,
  loadQuestions,
  generate,
  toggleSelected,
  toggleSelectAllFiltered,
  startEditing,
  cancelEditing,
  setEditingCorrectOption,
  saveEditing,
  publishSelected,
  unpublishSelected,
  deleteQuestion,
} = useReviewBotAdminModule()
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.rbg-view {
  display: grid;
  gap: 16px;
}

.rbg-head {
  display: grid;
  gap: 8px;
  background: #f4f7ff;
  border-color: rgba(31, 65, 143, 0.18);
}

.rbg-kicker {
  margin: 0;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #234a9c;
}

.rbg-head h3 {
  margin: 0;
  color: var(--lnu-navy-deep);
}

.rbg-head p {
  margin: 0;
  color: var(--lnu-text-muted);
  line-height: 1.5;
}

.rbg-generate,
.rbg-list {
  display: grid;
  gap: 14px;
  padding: 18px;
}

.rbg-generate-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.rbg-generate-actions {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}

.rbg-list-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.rbg-bulk-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  border-radius: 12px;
  background: #eef3ff;
  border: 1px solid rgba(31, 65, 143, 0.14);
  flex-wrap: wrap;
}

.rbg-select-all,
.rbg-select-one {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
  color: var(--lnu-navy-deep);
}

.rbg-bulk-actions {
  display: flex;
  gap: 8px;
}

.rbg-question-list {
  display: grid;
  gap: 14px;
}

.rbg-question-card {
  display: grid;
  gap: 10px;
  padding: 16px;
  border-radius: 16px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  background: #ffffff;
}

.rbg-question-head {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.rbg-question-meta {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  flex: 1;
}

.rbg-question-actions {
  display: flex;
  gap: 8px;
}

.rbg-question-actions .danger {
  color: #ad5340;
  border-color: rgba(173, 83, 64, 0.3);
}

.rbg-question-text {
  margin: 0;
  font-size: 15px;
  color: var(--lnu-text);
  line-height: 1.5;
}

.rbg-option-list {
  margin: 0;
  padding-left: 18px;
  display: grid;
  gap: 4px;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.rbg-option-list .is-correct {
  color: #267242;
  font-weight: 700;
}

.rbg-explanation {
  margin: 0;
  padding: 10px 12px;
  border-radius: 10px;
  background: #f6f8fd;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.rbg-edit-options {
  display: grid;
  gap: 8px;
}

.rbg-edit-option {
  display: flex;
  align-items: center;
  gap: 8px;
}

.rbg-edit-option .text-input {
  flex: 1;
}

.rbg-edit-actions {
  display: flex;
  gap: 10px;
}

@media (max-width: 900px) {
  .rbg-generate-grid {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 560px) {
  .rbg-generate-grid {
    grid-template-columns: 1fr;
  }
}
</style>
