<template>
  <section class="library-view">
    <div v-if="libraryMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ libraryMessage }}</span>
    </div>
    <div v-if="libraryError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ libraryError }}</span>
    </div>

    <article class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <BookOpen :size="18" />
          <h3>Assessment Library</h3>
        </div>
        <button type="button" class="primary-btn add-room-btn" @click="openLibraryQuestionModal">
          <Plus :size="16" />
          Add Questions
        </button>
      </header>

      <div v-if="libraryLoading && libraryQuestionBanks.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading question banks</h4>
        <p>Please wait while we fetch your library entries.</p>
      </div>

      <div v-else-if="libraryQuestionBanks.length === 0" class="room-empty-state">
        <BookOpen :size="42" />
        <h4>No Question Banks Yet</h4>
        <p>Upload a DOCX to convert assessment questions and queue AI item mapping in the background.</p>
      </div>

      <div v-else class="management-list">
        <article v-for="bank in libraryQuestionBanks" :key="bank.id" class="management-item">
          <div class="management-item-main">
            <div class="management-item-heading">
              <strong>{{ bank.title }}</strong>
              <span class="pill success">{{ bank.total_items }} assessment item(s)</span>
            </div>
            <p>{{ bank.subject || 'General subject' }}</p>

            <div class="management-meta-grid">
              <div>
                <small>Source</small>
                <strong>{{ bank.source_filename || 'Manual entry' }}</strong>
              </div>
              <div>
                <small>Updated</small>
                <strong>{{ formatDateTime(bank.updated_at) }}</strong>
              </div>
              <div>
                <small>Item Mapping</small>
                <strong>
                  <span class="pill" :class="resolveGenerationStatusClass(bank.mapping_status)">
                    <RefreshCw v-if="['queued', 'processing'].includes(bank.mapping_status)" :size="11" class="spin-soft" />
                    {{ formatGenerationStatus(bank.mapping_status) }}
                  </span>
                </strong>
              </div>
            </div>

            <p v-if="bank.mapping_message" class="muted">
              {{ bank.mapping_message }}
            </p>
          </div>

          <div class="management-actions">
            <button
              type="button"
              class="ghost-btn"
              :disabled="libraryBankQuestionsLoading"
              @click="openLibraryBankQuestionsModal(bank)"
            >
              <Eye :size="15" />
              View Questions
            </button>
            <button
              type="button"
              class="ghost-btn"
              :disabled="libraryMappingSaving || ['queued', 'processing'].includes(bank.mapping_status)"
              @click="queueBankMapping(bank.id)"
            >
              <Sparkles :size="15" />
              Map Items with AI
            </button>
            <button
              type="button"
              class="danger-btn"
              :disabled="libraryDeleting"
              @click="openDeleteLibraryBankModal(bank)"
            >
              <Trash2 :size="15" />
              Delete
            </button>
          </div>
        </article>
      </div>
    </article>

    <teleport to="body">
      <div v-if="showLibraryQuestionModal" class="modal-backdrop" @click.self="closeLibraryQuestionModal">
        <div class="modal-card library-modal-card">
          <header class="modal-head library-modal-head">
            <div class="library-modal-title-wrap">
              <h4>Add Assessment Questions</h4>
              <p>Upload your DOCX, tag optional topics, and the system will queue AI item mapping after save.</p>
            </div>
            <button type="button" class="modal-close" @click="closeLibraryQuestionModal">
              <X :size="16" />
            </button>
          </header>

          <div class="library-modal-body">
            <section class="library-form-panel">
              <label class="field-stack">
                <span class="field-label">Question Name</span>
                <input
                  v-model.trim="libraryForm.questionName"
                  type="text"
                  class="text-input"
                  maxlength="255"
                  placeholder="e.g. Mock Exam - Library Science Set A"
                />
              </label>

              <label class="field-stack">
                <span class="field-label">Subject Category</span>
                <select v-model="libraryForm.subjectCategory" class="text-input">
                  <option disabled value="">Select subject category</option>
                  <option v-for="subject in librarySubjectCategories" :key="subject" :value="subject">
                    {{ subject }}
                  </option>
                </select>
              </label>

              <label class="field-stack">
                <span class="field-label">AI Item Mapping</span>
                <select v-model="libraryForm.aiTaggingMode" class="text-input">
                  <option value="review">Suggest topics for my review (recommended)</option>
                  <option value="auto">Auto-label — apply immediately</option>
                </select>
                <p class="library-upload-note">
                  Every item gets an AI-suggested topic, difficulty, and cognitive level so exam results can show a deeper
                  breakdown. Choose whether suggestions apply right away or wait for you to confirm them.
                </p>
              </label>

              <label class="field-stack">
                <span class="field-label">Upload a DOCX file (Questions)</span>
                <div class="library-upload-panel">
                  <input
                    :key="libraryFileInputKey"
                    type="file"
                    accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    class="file-input"
                    :disabled="libraryParsing"
                    @change="handleLibraryDocxChange"
                  />
                  <p class="library-upload-note">
                    Use numbered questions with optional choices like <code>A.</code>, <code>B.</code>, <code>C.</code>.
                  </p>
                </div>
              </label>

              <p v-if="libraryDocxName" class="library-file-chip">{{ libraryDocxName }}</p>

              <div v-if="libraryParsing" class="feedback info">
                <RefreshCw :size="15" class="spin-soft" />
                <span>Converting DOCX to digitalized questions...</span>
              </div>

              <div v-if="libraryParseError" class="feedback danger">
                <AlertCircle :size="15" />
                <span>{{ libraryParseError }}</span>
              </div>

              <div v-if="groupedLibraryPreviewWarnings.length > 0" class="library-warning-list">
                <p class="warning-list-title">Parser Notes</p>
                <ul>
                  <li v-for="(warning, warningIndex) in groupedLibraryPreviewWarnings" :key="`warning-${warningIndex}`">
                    {{ warning }}
                  </li>
                </ul>
              </div>
            </section>

            <section class="digitalized-preview">
              <header class="digitalized-head">
                <h5>Assessment Questions</h5>
                <span class="pill neutral">{{ digitalizedQuestions.length }} items</span>
              </header>

              <div v-if="digitalizedQuestions.length === 0" class="digitalized-empty">
                <FileText :size="30" />
                <p>Converted questions will appear here after DOCX upload.</p>
              </div>

              <div v-else class="digitalized-list">
                <article
                  v-for="(question, index) in digitalizedQuestions"
                  :key="`${question.id}-${index}`"
                  class="digitalized-card"
                >
                  <p class="digitalized-question">{{ index + 1 }}. {{ question.text }}</p>

                  <div class="room-form-grid" style="margin-bottom: 12px;">
                    <label class="field-stack">
                      <span class="field-label">Topic (optional)</span>
                      <input
                        v-model.trim="question.topic_name"
                        type="text"
                        class="text-input"
                        placeholder="e.g. Dewey Decimal Classification"
                      />
                    </label>
                  </div>

                  <div v-if="question.options.length > 0" class="digitalized-options">
                    <label
                      v-for="(option, optionIndex) in question.options"
                      :key="optionIndex"
                      class="digitalized-option"
                      :class="{ correct: option.is_correct }"
                    >
                      <input type="radio" :name="`preview-question-${index}`" :checked="option.is_correct" disabled />
                      <span>{{ option.label }}. {{ option.text }}</span>
                    </label>
                  </div>

                  <p v-else class="digitalized-open-ended">Open-ended response</p>

                  <p v-if="question.answer_label" class="digitalized-answer">
                    Answer: {{ question.answer_label }}<span v-if="question.answer_text">. {{ question.answer_text }}</span>
                  </p>
                </article>
              </div>
            </section>
          </div>

          <div class="modal-actions library-modal-actions">
            <button type="button" class="ghost-btn" :disabled="librarySaving" @click="closeLibraryQuestionModal">Close</button>
            <button
              type="button"
              class="primary-btn"
              :disabled="!canSaveLibraryQuestionBank"
              @click="handleSaveLibraryQuestionBank"
            >
              <RefreshCw v-if="librarySaving" :size="16" class="spin-soft" />
              <Plus v-else :size="16" />
              {{ librarySaving ? 'Saving...' : 'Save Question Bank' }}
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showLibraryBankQuestionsModal" class="modal-backdrop" @click.self="closeLibraryBankQuestionsModal">
        <div class="modal-card library-bank-view-modal">
          <header class="modal-head library-modal-head">
            <div class="library-modal-title-wrap">
              <h4>{{ selectedLibraryQuestionBank?.title || 'Question Bank' }}</h4>
              <p>
                {{ selectedLibraryQuestionBank?.subject || 'General subject' }}
                <span v-if="selectedLibraryQuestionBank?.total_items">• {{ selectedLibraryQuestionBank.total_items }} assessment item(s)</span>
              </p>
            </div>
            <button type="button" class="modal-close" @click="closeLibraryBankQuestionsModal">
              <X :size="16" />
            </button>
          </header>

          <div class="library-bank-view-body">
            <div v-if="selectedLibraryQuestionBank" class="library-bank-view-meta">
              <span class="pill neutral">{{ libraryBankQuestions.length }} assessment item(s)</span>
              <span v-if="selectedLibraryQuestionBank.subject" class="pill navy">{{ selectedLibraryQuestionBank.subject }}</span>
            </div>

            <div v-if="libraryBankQuestionsLoading" class="room-empty-state compact">
              <RefreshCw :size="30" class="spin-soft" />
              <p>Loading question bank items...</p>
            </div>

            <div v-else-if="libraryBankQuestions.length === 0" class="room-empty-state compact">
              <BookOpen :size="30" />
              <p>No assessment questions were found in this bank.</p>
            </div>

            <div v-else class="library-bank-question-list">
              <article
                v-for="question in libraryBankQuestions"
                :key="question.id"
                class="library-bank-question-card"
              >
                <div class="library-bank-question-head">
                  <strong>#{{ question.item_number }} {{ question.question_text }}</strong>
                  <div class="library-bank-question-pills">
                    <span class="pill neutral">{{ (question.question_type || 'multiple_choice').replace('_', ' ') }}</span>
                    <span v-if="question.topic?.name" class="pill navy">{{ question.topic.name }}</span>
                    <span v-if="question.difficulty" class="pill success">{{ question.difficulty }}</span>
                    <span v-if="question.cognitive_level" class="pill neutral">{{ cognitiveLevelLabel(question.cognitive_level) }}</span>
                    <span v-if="question.tag_status === 'pending_review'" class="pill neutral">Tag pending review</span>
                  </div>
                </div>

                <div v-if="Array.isArray(question.options) && question.options.length > 0" class="library-bank-option-list">
                  <div
                    v-for="option in question.options"
                    :key="option.id"
                    class="library-bank-option-row"
                    :class="{ correct: option.is_correct }"
                  >
                    <span class="library-bank-option-label">{{ option.option_label }}.</span>
                    <span>{{ option.option_text }}</span>
                  </div>
                </div>

                <p v-else class="digitalized-open-ended">Open-ended response</p>

                <p class="digitalized-answer">
                  Answer:
                  <span v-if="question.answer_label">{{ question.answer_label }}</span>
                  <span v-if="question.answer_text">. {{ question.answer_text }}</span>
                  <span v-if="!question.answer_label && !question.answer_text">No answer key stored.</span>
                </p>
              </article>
            </div>
          </div>

          <div class="modal-actions">
            <button
              v-if="libraryBankQuestions.some((question) => question.tag_status === 'pending_review')"
              type="button"
              class="ghost-btn"
              :disabled="libraryMappingSaving"
              @click="confirmBankTags(selectedLibraryQuestionBank?.id)"
            >
              <CheckCircle2 :size="15" />
              Confirm All Suggested Tags
            </button>
            <button type="button" class="ghost-btn" @click="closeLibraryBankQuestionsModal">Close</button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showDeleteLibraryBankModal" class="modal-backdrop" @click.self="closeDeleteLibraryBankModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Delete Question Set</h4>
            <button type="button" class="modal-close" @click="closeDeleteLibraryBankModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            Delete <strong>{{ selectedLibraryBank?.title }}</strong>? This removes all assessment questions linked to the bank.
          </p>
          <p class="muted">
            This action is blocked if the set already has official exam attempt records.
          </p>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="libraryDeleting" @click="closeDeleteLibraryBankModal">
              Cancel
            </button>
            <button type="button" class="danger-btn" :disabled="libraryDeleting" @click="handleDeleteLibraryBank">
              <RefreshCw v-if="libraryDeleting" :size="14" class="spin-soft" />
              <Trash2 v-else :size="14" />
              <span>{{ libraryDeleting ? 'Deleting...' : 'Delete Question Set' }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import { AlertCircle, BookOpen, CheckCircle2, Eye, FileText, Plus, RefreshCw, Sparkles, Trash2, X } from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useLibraryModule } from '../composables/useLibraryModule'

function formatGenerationStatus(status) {
  const normalized = String(status ?? 'idle').toLowerCase()

  if (normalized === 'pending_review') return 'Pending Review'

  return normalized
    .split('_')
    .map(segment => segment.charAt(0).toUpperCase() + segment.slice(1))
    .join(' ')
}

function resolveGenerationStatusClass(status) {
  switch (String(status ?? 'idle').toLowerCase()) {
    case 'queued':
    case 'processing':
      return 'neutral'
    case 'completed':
      return 'success'
    case 'failed':
    case 'skipped':
      return 'danger'
    default:
      return 'neutral'
  }
}

function questionTypeLabel(type) {
  return String(type ?? 'multiple_choice')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase())
}

function cognitiveLevelLabel(level) {
  return questionTypeLabel(level || 'comprehension')
}

const {
  showLibraryQuestionModal,
  showLibraryBankQuestionsModal,
  showDeleteLibraryBankModal,
  libraryLoading,
  libraryBankQuestionsLoading,
  librarySaving,
  libraryDeleting,
  libraryMappingSaving,
  libraryError,
  libraryMessage,
  libraryParsing,
  libraryParseError,
  libraryDocxName,
  libraryFileInputKey,
  libraryQuestionBanks,
  selectedLibraryBank,
  selectedLibraryQuestionBank,
  groupedLibraryPreviewWarnings,
  digitalizedQuestions,
  libraryBankQuestions,
  libraryForm,
  librarySubjectCategories,
  canSaveLibraryQuestionBank,
  openLibraryQuestionModal,
  closeLibraryQuestionModal,
  openLibraryBankQuestionsModal,
  closeLibraryBankQuestionsModal,
  openDeleteLibraryBankModal,
  closeDeleteLibraryBankModal,
  handleLibraryDocxChange,
  handleSaveLibraryQuestionBank,
  handleDeleteLibraryBank,
  queueBankMapping,
  confirmBankTags,
} = useLibraryModule()
</script>

<style scoped src="../dashboard.css"></style>
<style scoped>
.management-meta-grid .pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.library-bank-view-modal {
  width: min(1120px, calc(100vw - 32px));
}

.library-bank-view-body {
  display: grid;
  gap: 16px;
}

.library-bank-view-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.library-bank-question-list {
  display: grid;
  gap: 14px;
  max-height: min(70vh, 760px);
  overflow-y: auto;
  padding-right: 4px;
}

.library-bank-question-card {
  border: 1px solid rgba(13, 21, 71, 0.1);
  border-radius: 16px;
  background: #fff;
  padding: 16px;
  display: grid;
  gap: 12px;
}

.library-bank-question-head {
  display: grid;
  gap: 10px;
}

.library-bank-question-head strong {
  color: var(--lnu-navy-deep);
  line-height: 1.45;
}

.library-bank-question-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.library-bank-option-list {
  display: grid;
  gap: 8px;
}

.library-bank-option-row {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 10px 12px;
  border-radius: 12px;
  background: rgba(13, 21, 71, 0.04);
  color: var(--lnu-text);
}

.library-bank-option-row.correct {
  background: rgba(46, 125, 50, 0.08);
  border: 1px solid rgba(46, 125, 50, 0.14);
}

.library-bank-option-label {
  min-width: 20px;
  font-weight: 700;
  color: var(--lnu-navy-deep);
}
</style>
