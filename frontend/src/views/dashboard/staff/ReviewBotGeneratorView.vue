<template>
  <section class="rbg-view">
    <article class="surface-card rbg-head">
      <p class="rbg-kicker">Review Bot Question Generator</p>
      <h3>
        Turn your Library files into Review Bot questions
        <InfoTip text="Pick a file. The Review Bot reads it (the subject, and each question's own topic and subtopic) and generates a fresh, original review set on its own. Students see the questions only after you review and publish them." />
      </h3>
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

      <!-- One button per file. This was a six-column table, which scrolled
           sideways at every screen size because of the file name column. -->
      <ul v-else class="rbg-file-list">
        <li v-for="bank in libraryBanks" :key="bank.id">
          <button type="button" class="rbg-file-row" @click="selectBank(bank.id)">
            <span class="rbg-file-icon" aria-hidden="true"><FileText :size="17" /></span>
            <span class="rbg-file-copy">
              <strong>{{ bank.title }}</strong>
              <span>
                {{ bank.subject || 'No subject' }} · {{ bank.questionsCount }} item{{ bank.questionsCount === 1 ? '' : 's' }}<template v-if="seesEveryTeacher && bank.ownerName"> · By {{ bank.ownerName }}</template>
              </span>
              <small v-if="bank.sourceFilename" :title="bank.sourceFilename">{{ bank.sourceFilename }}</small>
            </span>
            <ChevronRight :size="18" class="rbg-file-chevron" aria-hidden="true" />
          </button>
        </li>
      </ul>
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
            <div v-scroll-hint class="rbg-status-tabs" role="tablist" aria-label="Show questions by status">
              <button
                v-for="tab in statusTabs"
                :key="tab.value"
                type="button"
                role="tab"
                class="rbg-status-tab"
                :class="{ active: statusFilter === tab.value }"
                :aria-selected="(statusFilter === tab.value).toString()"
                @click="statusFilter = tab.value"
              >
                {{ tab.label }}
                <span class="rbg-status-count">{{ tab.count }}</span>
              </button>
            </div>

            <button type="button" class="ghost-btn" @click="toggleCoverage">
              <ListChecks :size="14" />
              {{ coverageOpen ? 'Hide coverage' : 'Coverage' }}
            </button>

            <button type="button" class="ghost-btn" :disabled="questionsLoading" @click="loadQuestions">
              <RefreshCw :size="14" :class="{ 'spin-soft': questionsLoading }" /> Refresh
            </button>
          </div>
        </header>

        <section v-if="coverageOpen" class="rbg-coverage">
          <div v-if="coverageError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ coverageError }}</span>
          </div>

          <div v-else-if="coverageLoading" class="review-bot-empty compact">
            <RefreshCw :size="16" class="spin-soft" />
            <span>Working out which exam items are covered&hellip;</span>
          </div>

          <template v-else-if="coverage.length > 0">
            <p class="muted rbg-coverage-summary">
              <strong>{{ coverageSummary.complete }}</strong> of {{ coverageSummary.total }} exam items have all four
              cognitive levels &middot; <strong>{{ coverageSummary.partial }}</strong> partly covered &middot;
              <strong>{{ coverageSummary.empty }}</strong> with nothing yet. A declined question counts as missing, so
              its level shows here again.
            </p>

            <div class="directory-table-wrap rbg-coverage-wrap">
              <table class="directory-table rbg-coverage-table">
                <thead>
                  <tr>
                    <th class="directory-table-name-cell">Exam Item</th>
                    <th class="numeric">Live</th>
                    <th class="numeric">Published</th>
                    <th class="numeric">Declined</th>
                    <th>Missing Levels</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in coverage" :key="row.source_question_id">
                    <td class="directory-table-name-cell">
                      <strong>Item {{ row.item_number }}</strong>
                      <small class="muted">{{ row.question_text }}</small>
                    </td>
                    <td class="numeric" data-label="Live">{{ row.total }}</td>
                    <td class="numeric" data-label="Published">{{ row.published }}</td>
                    <td class="numeric" data-label="Declined">{{ row.rejected }}</td>
                    <td class="rbg-coverage-levels">
                      <span v-if="row.missing_levels.length === 0" class="pill success">Complete</span>
                      <span v-else class="rbg-coverage-missing">
                        <span v-for="level in row.missing_levels" :key="level" class="pill pill-cap neutral">{{ level }}</span>
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>

          <div v-else class="review-bot-empty compact">
            <ListChecks :size="16" />
            <span>No exam items found for this file's subject.</span>
          </div>
        </section>

        <div v-if="questionsError" class="feedback danger"><AlertCircle :size="15" /><span>{{ questionsError }}</span></div>

        <div v-if="actionMessage" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ actionMessage }}</span></div>

        <!-- Each tab offers only what can be done to the questions on it, and
             each button counts the selected questions it would change. -->
        <div v-if="filteredQuestions.length > 0" class="rbg-bulk-bar">
          <label class="rbg-select-all">
            <input type="checkbox" :checked="allFilteredSelected" @change="toggleSelectAllFiltered" />
            <span>{{ selectedIds.length > 0 ? `${selectedIds.length} selected` : `Select all (${filteredQuestions.length})` }}</span>
          </label>

          <div class="rbg-bulk-actions">
            <button v-if="['draft', 'all'].includes(statusFilter)" type="button" class="primary-btn" :disabled="publishableIds.length === 0" @click="publishSelected">
              Map &amp; Publish ({{ publishableIds.length }})
            </button>
            <button v-if="['published', 'all'].includes(statusFilter)" type="button" class="ghost-btn" :disabled="unpublishableIds.length === 0" @click="unpublishSelected">
              Unpublish ({{ unpublishableIds.length }})
            </button>
            <button v-if="statusFilter !== 'rejected'" type="button" class="ghost-btn" :disabled="declinableIds.length === 0" @click="rejectSelected">
              Decline ({{ declinableIds.length }})
            </button>
            <button v-if="statusFilter === 'rejected'" type="button" class="primary-btn" :disabled="restorableIds.length === 0" @click="restoreSelected">
              Restore to Draft ({{ restorableIds.length }})
            </button>
          </div>
        </div>

        <div v-if="generationPending && filteredQuestions.length === 0" class="review-bot-empty compact">
          <RefreshCw :size="18" class="spin-soft" />
          <p>Generating your review questions in the background. They appear here as they are made; there is no need to refresh.</p>
        </div>

        <!-- Only when there is nothing to show yet. A reload of a list that
             is on screen keeps it there: replacing it with this lost the
             teacher's place, and whatever draft they were reading. -->
        <div v-else-if="questionsLoading && filteredQuestions.length === 0" class="review-bot-empty compact">
          <RefreshCw :size="18" class="spin-soft" />
          <p>Loading questions...</p>
        </div>

        <div v-else-if="filteredQuestions.length === 0" class="review-bot-empty compact">
          <BookOpen :size="20" />
          <p>{{ emptyListMessage }}</p>
        </div>

        <div v-else class="rbg-question-list">
          <!-- More are still on their way: the list above is not yet all of it. -->
          <div v-if="generationPending" class="feedback info" role="status">
            <RefreshCw :size="14" class="spin-soft" />
            <span>Still generating. New drafts are added to this list as they are made.</span>
          </div>

          <article v-for="question in filteredQuestions" :key="question.id" class="rbg-question-card">
            <div class="rbg-question-head">
              <label class="rbg-select-one">
                <input type="checkbox" :checked="selectedIds.includes(question.id)" @change="toggleSelected(question.id)" />
              </label>

              <div class="rbg-question-meta">
                <span class="pill" :class="STATUS_PILLS[question.status]?.tone ?? 'gold'">{{ STATUS_PILLS[question.status]?.label ?? question.status }}</span>
                <span class="pill neutral">{{ question.subject }}</span>
                <span v-if="question.topic" class="pill neutral">{{ question.topic }}</span>
                <span v-if="question.subtopic" class="pill neutral">{{ question.subtopic }}</span>
                <span class="pill pill-cap navy">{{ question.difficulty }}</span>
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
import InfoTip from '@/components/InfoTip.vue'
import { computed } from 'vue'
import { AlertCircle, BookOpen, CheckCircle2, ChevronRight, FileText, ListChecks, RefreshCw, Sparkles } from 'lucide-vue-next'
import { useReviewBotAdminModule } from '../composables/useReviewBotAdminModule'
import { vScrollHint } from '@/utils/scrollHint'
import { useAuthStore } from '@/store/auth.store'
import { overseesAll } from '@/utils/owners'

const seesEveryTeacher = computed(() => overseesAll(useAuthStore().user))

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
  statusCounts,
  actionMessage,
  publishableIds,
  unpublishableIds,
  declinableIds,
  restorableIds,
  restoreSelected,
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
  rejectSelected,
  coverage,
  coverageLoading,
  coverageError,
  coverageOpen,
  coverageSummary,
  toggleCoverage,
  unpublishSelected,
  deleteQuestion,
} = useReviewBotAdminModule()

// "Declined" is the word on the page; the stored status is "rejected".
const STATUS_PILLS = {
  draft: { label: 'Draft', tone: 'gold' },
  published: { label: 'Published', tone: 'success' },
  rejected: { label: 'Declined', tone: 'neutral' },
}

// All leaves declined questions out, as the list it shows does.
const statusTabs = computed(() => [
  { value: 'draft', label: 'Draft', count: statusCounts.value.draft },
  { value: 'published', label: 'Published', count: statusCounts.value.published },
  { value: 'all', label: 'All', count: statusCounts.value.draft + statusCounts.value.published },
  { value: 'rejected', label: 'Declined', count: statusCounts.value.rejected },
])

const emptyListMessage = computed(() => ({
  draft: statusCounts.value.published > 0
    ? 'No drafts waiting for review. Everything generated from this file has been published or declined.'
    : 'No review questions generated from this file yet. Generate a review exam above.',
  published: 'Nothing from this file is published yet. Select drafts and choose Map & Publish.',
  all: 'No review questions generated from this file yet. Generate a review exam above.',
  rejected: 'No declined questions for this file.',
}[statusFilter.value]))
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.rbg-coverage {
  display: grid;
  gap: 10px;
  margin: 0 18px 14px;
}

.rbg-coverage-summary {
  margin: 0;
  line-height: 1.5;
}

.rbg-coverage-missing {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
}

.rbg-coverage-wrap {
  margin: 0;
}

/* Five short columns: far less than the 1080px the shared table asks for. */
.rbg-coverage-table {
  min-width: 0;
}

/* The shared header cell has no padding of its own, because elsewhere a sort
   button inside it brings the padding. These headings are plain text, so
   they take that button's spacing and type here. */
.rbg-coverage-table thead th {
  padding: 10px 12px;
  font-size: 11.5px;
  font-weight: 600;
  letter-spacing: 0.03em;
}

.rbg-coverage-table th.numeric {
  text-align: right;
}

.rbg-coverage-table .directory-table-name-cell {
  position: static;
  min-width: 0;
  width: 46%;
  box-shadow: none;
  white-space: normal;
}

.rbg-coverage-table td small.muted {
  white-space: normal;
  overflow-wrap: anywhere;
}

.rbg-coverage-table .rbg-coverage-levels {
  white-space: normal;
}

/* Phones: one block per exam item, its three counts on a line with their
   names, since the column headings are gone. */
@media (max-width: 640px) {
  .rbg-coverage-table,
  .rbg-coverage-table tbody,
  .rbg-coverage-table tr,
  .rbg-coverage-table td {
    display: block;
  }

  .rbg-coverage-table thead {
    display: none;
  }

  .rbg-coverage-table tr {
    padding: 12px;
    border-bottom: 1px solid var(--border-soft);
  }

  .rbg-coverage-table tbody tr:last-child {
    border-bottom: 0;
  }

  .rbg-coverage-table td {
    padding: 0;
    border-bottom: 0;
  }

  .rbg-coverage-table .directory-table-name-cell {
    width: auto;
    margin-bottom: 8px;
  }

  .rbg-coverage-table td.numeric {
    display: inline-block;
    margin: 0 14px 8px 0;
    text-align: left;
    font-weight: 600;
    color: var(--lnu-navy-deep);
  }

  .rbg-coverage-table td.numeric::before {
    content: attr(data-label) ' ';
    font-weight: 400;
    color: var(--ink-soft);
  }
}
</style>

<style scoped>
.rbg-view {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 16px;
}

.rbg-head {
  display: grid;
  gap: 8px;
  background: #f4f7ff;
  border-color: rgba(var(--lnu-info-rgb), 0.18);
}

.rbg-kicker {
  margin: 0;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--lnu-info);
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

.rbg-file-list {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 18px;
  overflow: hidden;
}

.rbg-file-list > li + li {
  border-top: 1px solid rgba(13, 21, 71, 0.08);
}

.rbg-file-row {
  width: 100%;
  min-height: 64px;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 6px 12px;
  padding: 12px 14px;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.12s ease;
}

.rbg-file-row:hover {
  background: rgba(var(--lnu-info-rgb), 0.06);
}

.rbg-file-row:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: -2px;
}

.rbg-file-icon {
  flex-shrink: 0;
  width: 38px;
  height: 38px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(26, 35, 126, 0.08);
  color: var(--lnu-navy);
}

.rbg-file-copy {
  flex: 1 1 auto;
  min-width: 0;
  display: grid;
  gap: 2px;
}

.rbg-file-copy strong {
  font-size: 14px;
  font-weight: 600;
  line-height: 1.35;
  color: var(--lnu-navy-deep);
  overflow-wrap: anywhere;
}

.rbg-file-copy span {
  font-size: 13px;
  color: var(--lnu-text-muted);
}

/* The uploaded file's name: long, unbroken, and the least needed. */
.rbg-file-copy small {
  font-size: 12px;
  color: var(--lnu-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.rbg-file-chevron {
  flex-shrink: 0;
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
  border-radius: 14px;
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
  grid-template-columns: minmax(0, 1fr);
  gap: 14px;
  padding: 18px;
}

.rbg-list-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.rbg-status-tabs {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  border-radius: 999px;
  background: #eef1f8;
  border: 1px solid rgba(13, 21, 71, 0.08);
  max-width: 100%;
  min-width: 0;
  overflow-x: auto;
  scrollbar-width: none;
}

.rbg-status-tabs::-webkit-scrollbar {
  display: none;
}

/* Phones: the four tabs share the full width instead of scrolling. */
@media (max-width: 640px) {
  .rbg-status-tabs {
    display: flex;
    width: 100%;
    gap: 0;
    padding: 3px;
  }

  .rbg-status-tabs .rbg-status-tab {
    flex: 1 1 auto;
    justify-content: center;
    gap: 4px;
    padding-inline: 3px;
    font-size: 12px;
  }

  .rbg-status-tabs .rbg-status-count {
    min-width: 0;
    padding-inline: 5px;
  }

  .rbg-bulk-actions {
    width: 100%;
  }

  .rbg-bulk-actions > button {
    flex: 1 1 auto;
  }
}

.rbg-status-tab {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  min-height: 32px;
  padding: 0 12px;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--ink-soft);
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
}

.rbg-status-tab:hover {
  color: var(--lnu-navy-deep);
}

.rbg-status-tab.active {
  background: var(--panel-navy);
  color: #ffffff;
}

.rbg-status-count {
  min-width: 22px;
  padding: 1px 7px;
  border-radius: 999px;
  background: rgba(13, 21, 71, 0.08);
  font-size: 11px;
  font-weight: 700;
  text-align: center;
}

.rbg-status-tab.active .rbg-status-count {
  background: rgba(255, 255, 255, 0.18);
}

.rbg-bulk-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  border-radius: 14px;
  background: #eef3ff;
  border: 1px solid rgba(var(--lnu-info-rgb), 0.14);
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
  flex-wrap: wrap;
}

.rbg-question-list {
  display: grid;
  gap: 14px;
}

.rbg-question-card {
  display: grid;
  gap: 10px;
  padding: 16px;
  border-radius: 20px;
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

  /* The introduction is read once; the file list is the page. */
  .rbg-head {
    padding: 16px;
  }

  .rbg-head h3 {
    font-size: 17px;
    line-height: 1.35;
  }

  .rbg-head p:not(.rbg-kicker) {
    font-size: 13.5px;
  }

  .rbg-generate,
  .rbg-list {
    padding: 14px;
  }
}

@media (pointer: coarse) {
  .rbg-status-tab {
    min-height: 40px;
  }
}
</style>
