<template>
  <section class="rbg-view">
    <article class="surface-card rbg-head">
      <p class="rbg-kicker">Review Bot Question Generator</p>
      <h3>Turn your Library files into Review Bot questions</h3>
      <p>
        Pick a file. The Review Bot reads it — subject, and each question's own topic/subtopic mapping —
        and generates a fresh, original review set on its own. Review and publish when you're happy with it.
      </p>
    </article>

    <div v-if="catalogError" class="feedback danger"><AlertCircle :size="15" /><span>{{ catalogError }}</span></div>
    <div v-if="libraryBanksError" class="feedback danger"><AlertCircle :size="15" /><span>{{ libraryBanksError }}</span></div>

    <!-- Library — pick the exam file this review batch comes from. -->
    <article v-if="!selectedBankId" class="surface-card rbg-generate">
      <header class="review-section-head">
        <h3>Select an exam file from your Library</h3>
        <button type="button" class="ghost-btn" :disabled="libraryBanksLoading" @click="loadLibraryBanks">
          <RefreshCw :size="14" :class="{ 'spin-soft': libraryBanksLoading }" /> Refresh
        </button>
      </header>

      <div v-if="libraryBanksLoading" class="review-bot-empty compact">
        <RefreshCw :size="18" class="spin-soft" />
        <p>Loading your Library files...</p>
      </div>

      <div v-else-if="libraryBanks.length === 0" class="review-bot-empty compact">
        <BookOpen :size="20" />
        <p>No files uploaded yet. Upload an exam question set in the Library first — the Review Bot generates from those, not from a separate upload here.</p>
      </div>

      <div v-else class="rbg-bank-table-wrap">
        <table class="rbg-bank-table">
          <thead>
            <tr>
              <th>File</th>
              <th>Subject</th>
              <th>Items</th>
              <th>Mapping</th>
              <th>Source</th>
              <th class="rbg-bank-table-action-head"></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="bank in libraryBanks"
              :key="bank.id"
              class="rbg-bank-row"
              tabindex="0"
              role="button"
              :aria-label="`Select ${bank.title}`"
              @click="selectBank(bank.id)"
              @keydown.enter="selectBank(bank.id)"
              @keydown.space.prevent="selectBank(bank.id)"
            >
              <td>
                <span class="rbg-bank-table-title"><FileText :size="15" />{{ bank.title }}</span>
              </td>
              <td><span class="pill neutral">{{ bank.subject || 'No subject' }}</span></td>
              <td>{{ bank.questionsCount }} item{{ bank.questionsCount === 1 ? '' : 's' }}</td>
              <td><span class="pill" :class="bank.mappingStatus === 'completed' ? 'success' : 'gold'">{{ bank.mappingStatus }}</span></td>
              <td class="review-muted">{{ bank.sourceFilename || '—' }}</td>
              <td class="rbg-bank-table-action"><ChevronRight :size="16" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>

    <template v-else>
      <article class="surface-card rbg-selected-file">
        <div class="rbg-selected-file-top">
          <div class="rbg-selected-file-info">
            <FileText :size="18" />
            <div>
              <p class="rbg-selected-file-title">{{ selectedBank?.title }}</p>
              <p class="review-muted">{{ selectedBank?.subject || 'No subject' }} &middot; {{ selectedBank?.questionsCount }} original item{{ selectedBank?.questionsCount === 1 ? '' : 's' }}</p>
            </div>
          </div>
          <div class="rbg-selected-file-actions">
            <button type="button" class="primary-btn" :disabled="generating || generationPending" @click="generate">
              <Sparkles :size="16" />
              {{ generating ? 'Queuing...' : (generationPending ? 'Generating...' : 'Generate Review Questions') }}
            </button>
            <button type="button" class="ghost-btn" @click="changeFile">Change file</button>
          </div>
        </div>

        <div v-if="generateError" class="feedback danger"><AlertCircle :size="15" /><span>{{ generateError }}</span></div>
        <div v-if="generateMessage" class="feedback success"><CheckCircle2 :size="15" /><span>{{ generateMessage }}</span></div>
      </article>

      <!-- Preview — the original exam items, read-only, kept clearly separate -->
      <!-- from the AI-generated batch below. -->
      <details class="surface-card rbg-preview">
        <summary class="review-section-head rbg-preview-summary">
          <h3>Original exam questions in this file</h3>
          <span class="pill neutral">{{ sourceQuestions.length }} item{{ sourceQuestions.length === 1 ? '' : 's' }}</span>
        </summary>

        <div v-if="sourceQuestionsError" class="feedback danger"><AlertCircle :size="15" /><span>{{ sourceQuestionsError }}</span></div>

        <div v-if="sourceQuestionsLoading" class="review-bot-empty compact">
          <RefreshCw :size="18" class="spin-soft" />
          <p>Loading questions...</p>
        </div>

        <div v-else-if="sourceQuestions.length === 0" class="review-bot-empty compact">
          <BookOpen :size="20" />
          <p>This file has no readable questions.</p>
        </div>

        <ol v-else class="rbg-source-list">
          <li v-for="question in sourceQuestions" :key="question.id" class="rbg-source-item">
            <div class="rbg-source-item-head">
              <span class="review-muted">Item {{ question.itemNumber }}</span>
              <span v-if="question.topic" class="pill neutral">{{ question.topic }}</span>
            </div>
            <p class="rbg-question-text">{{ question.questionText }}</p>
            <ul v-if="question.options.length > 0" class="rbg-option-list">
              <li v-for="option in question.options" :key="option.id" :class="{ 'is-correct': option.isCorrect }">
                {{ option.label }}. {{ option.text }}
              </li>
            </ul>
            <p v-else-if="question.answerText" class="review-muted">Answer: {{ question.answerText }}</p>
          </li>
        </ol>
      </details>

      <!-- Review/Edit, then Map/Assign (publish) -- the AI-generated batch, -->
      <!-- kept clearly separate from the original items above. -->
      <article class="surface-card rbg-list">
        <header class="review-section-head">
          <h3>Review, edit &amp; map the generated review questions</h3>

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

        <div v-if="questionsError" class="feedback danger"><AlertCircle :size="15" /><span>{{ questionsError }}</span></div>

        <div v-if="filteredQuestions.length > 0" class="rbg-bulk-bar">
          <label class="rbg-select-all">
            <input type="checkbox" :checked="allFilteredSelected" @change="toggleSelectAllFiltered" />
            <span>Select all ({{ filteredQuestions.length }})</span>
          </label>

          <div class="rbg-bulk-actions">
            <button type="button" class="primary-btn" :disabled="selectedIds.length === 0" @click="publishSelected">
              Map &amp; Publish Selected ({{ selectedIds.length }})
            </button>
            <button type="button" class="ghost-btn" :disabled="selectedIds.length === 0" @click="unpublishSelected">
              Unpublish Selected
            </button>
          </div>
        </div>

        <div v-if="generationPending && filteredQuestions.length === 0" class="review-bot-empty compact">
          <RefreshCw :size="18" class="spin-soft" />
          <p>Generating your review questions in the background — this can take a few minutes for a real AI call. This list updates on its own, no need to refresh.</p>
        </div>

        <div v-else-if="questionsLoading" class="review-bot-empty compact">
          <RefreshCw :size="18" class="spin-soft" />
          <p>Loading questions...</p>
        </div>

        <div v-else-if="filteredQuestions.length === 0" class="review-bot-empty compact">
          <BookOpen :size="20" />
          <p>No {{ statusFilter === 'all' ? '' : statusFilter }} questions generated from this file yet. Generate a review exam above.</p>
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
                <button v-if="!editing[question.id]" type="button" class="ghost-btn review-small-btn" @click="startEditing(question)">Edit / Map</button>
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

              <div class="rbg-edit-grid">
                <label class="field-stack">
                  <span class="field-label">Difficulty</span>
                  <select v-model="editing[question.id].difficulty" class="text-input">
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Hard</option>
                  </select>
                </label>

                <label class="field-stack">
                  <span class="field-label">Map to topic</span>
                  <select
                    class="text-input"
                    :value="editing[question.id].topicName"
                    @change="setEditingTopic(question.id, $event.target.value)"
                  >
                    <option value="">Unmapped</option>
                    <option v-for="topic in topicOptions" :key="topic" :value="topic">{{ topic }}</option>
                  </select>
                </label>

                <label class="field-stack">
                  <span class="field-label">Map to subtopic</span>
                  <select
                    v-model="editing[question.id].subtopicName"
                    class="text-input"
                    :disabled="!editing[question.id].topicName"
                  >
                    <option value="">Unmapped</option>
                    <option
                      v-for="subtopic in (curatedTopics[selectedBank?.subject]?.[editing[question.id].topicName] ?? [])"
                      :key="subtopic"
                      :value="subtopic"
                    >{{ subtopic }}</option>
                  </select>
                </label>
              </div>

              <div class="rbg-edit-actions">
                <button type="button" class="primary-btn" :disabled="savingQuestionId === question.id" @click="saveEditing(question.id)">
                  {{ savingQuestionId === question.id ? 'Saving...' : 'Save mapping' }}
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
    </template>
  </section>
</template>

<script setup>
import { AlertCircle, BookOpen, CheckCircle2, ChevronRight, FileText, RefreshCw, Sparkles } from 'lucide-vue-next'
import { useReviewBotAdminModule } from '../composables/useReviewBotAdminModule'

const {
  curatedTopics,
  catalogError,
  libraryBanks,
  libraryBanksLoading,
  libraryBanksError,
  selectedBankId,
  selectedBank,
  sourceQuestions,
  sourceQuestionsLoading,
  sourceQuestionsError,
  topicOptions,
  generating,
  generateError,
  generateMessage,
  generationPending,
  filteredQuestions,
  questionsLoading,
  questionsError,
  statusFilter,
  selectedIds,
  allFilteredSelected,
  editing,
  savingQuestionId,
  loadLibraryBanks,
  selectBank,
  changeFile,
  loadQuestions,
  generate,
  toggleSelected,
  toggleSelectAllFiltered,
  startEditing,
  setEditingTopic,
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

.rbg-bank-table-wrap {
  overflow-x: auto;
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 14px;
}

.rbg-bank-table {
  width: 100%;
  min-width: 640px;
  border-collapse: separate;
  border-spacing: 0;
}

.rbg-bank-table th,
.rbg-bank-table td {
  padding: 12px 14px;
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid rgba(13, 21, 71, 0.08);
}

.rbg-bank-table thead th {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--lnu-text-muted);
  background: #f6f8fd;
}

.rbg-bank-table tbody tr:last-child td {
  border-bottom: none;
}

.rbg-bank-row {
  cursor: pointer;
  transition: background-color 0.12s ease;
}

.rbg-bank-row:hover,
.rbg-bank-row:focus-visible {
  background: rgba(31, 65, 143, 0.06);
  outline: none;
}

.rbg-bank-table-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 700;
  font-size: 14px;
  color: var(--lnu-navy-deep);
}

.rbg-bank-table-action-head {
  width: 32px;
}

.rbg-bank-table-action {
  color: var(--lnu-text-muted);
}

.rbg-selected-file {
  display: grid;
  gap: 10px;
  padding: 14px 18px;
}

.rbg-selected-file-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.rbg-selected-file-info {
  display: flex;
  align-items: center;
  gap: 10px;
}

.rbg-selected-file-title {
  margin: 0;
  font-weight: 700;
  color: var(--lnu-navy-deep);
}

.rbg-selected-file-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.rbg-preview {
  padding: 18px;
}

.rbg-preview-summary {
  cursor: pointer;
  list-style: none;
}

.rbg-preview-summary::-webkit-details-marker {
  display: none;
}

.rbg-source-list {
  margin: 14px 0 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 12px;
  max-height: 420px;
  overflow-y: auto;
}

.rbg-source-item {
  display: grid;
  gap: 6px;
  padding: 12px 14px;
  border-radius: 12px;
  background: #f6f8fd;
  border: 1px solid rgba(13, 21, 71, 0.08);
}

.rbg-source-item-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
}

.rbg-generate,
.rbg-list {
  display: grid;
  gap: 14px;
  padding: 18px;
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

.rbg-edit-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.rbg-edit-actions {
  display: flex;
  gap: 10px;
}

@media (max-width: 900px) {
  .rbg-edit-grid {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 560px) {
  .rbg-edit-grid {
    grid-template-columns: 1fr;
  }

  .rbg-selected-file-top {
    flex-direction: column;
    align-items: flex-start;
  }

  .rbg-selected-file-actions {
    width: 100%;
  }

  .rbg-selected-file-actions .primary-btn {
    flex: 1;
    justify-content: center;
  }
}
</style>
