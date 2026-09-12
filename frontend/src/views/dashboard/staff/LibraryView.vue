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
        <p>Upload a DOCX to convert assessment questions and queue automatic item mapping in the background.</p>
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
              @click="openEditLibraryBankModal(bank)"
            >
              <Pencil :size="15" />
              Edit
            </button>
            <button
              type="button"
              class="ghost-btn"
              :disabled="libraryMappingSaving || ['queued', 'processing'].includes(bank.mapping_status)"
              @click="queueBankMapping(bank.id)"
            >
              <Sparkles :size="15" />
              Map Items
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
              <p>Pick a DOCX — the name, subject, and item mapping are all handled automatically on save.</p>
            </div>
            <button type="button" class="modal-close" @click="closeLibraryQuestionModal">
              <X :size="16" />
            </button>
          </header>

          <div class="library-modal-body">
            <section class="library-form-panel">
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

                <template v-if="editingQuestionId !== question.id">
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

                  <div class="library-bank-question-actions">
                    <button type="button" class="ghost-btn" @click="startEditingQuestion(question)">
                      <Pencil :size="14" />
                      Edit
                    </button>
                  </div>
                </template>

                <div v-else class="library-question-edit-form">
                  <label class="field-stack">
                    <span class="field-label">Question Text</span>
                    <textarea v-model.trim="questionEditForm.question_text" class="text-input" rows="3"></textarea>
                  </label>

                  <div class="room-form-grid">
                    <label class="field-stack">
                      <span class="field-label">Question Type</span>
                      <select v-model="questionEditForm.question_type" class="text-input">
                        <option v-for="type in libraryQuestionTypeOptions" :key="type" :value="type">
                          {{ questionTypeLabel(type) }}
                        </option>
                      </select>
                    </label>

                    <label class="field-stack">
                      <span class="field-label">Difficulty</span>
                      <select v-model="questionEditForm.difficulty" class="text-input">
                        <option value="">Not set</option>
                        <option v-for="level in libraryDifficultyOptions" :key="level" :value="level">
                          {{ questionTypeLabel(level) }}
                        </option>
                      </select>
                    </label>

                    <label class="field-stack">
                      <span class="field-label">Cognitive Level</span>
                      <select v-model="questionEditForm.cognitive_level" class="text-input">
                        <option value="">Not set</option>
                        <option v-for="level in libraryCognitiveLevelOptions" :key="level" :value="level">
                          {{ cognitiveLevelLabel(level) }}
                        </option>
                      </select>
                    </label>

                    <label class="field-stack">
                      <span class="field-label">Topic</span>
                      <select v-model="questionEditForm.topic_name" class="text-input" @change="handleQuestionEditTopicChange">
                        <option value="">Not set</option>
                        <option v-for="topic in questionEditTopicOptions" :key="topic" :value="topic">{{ topic }}</option>
                      </select>
                    </label>

                    <label class="field-stack">
                      <span class="field-label">Subtopic</span>
                      <select v-model="questionEditForm.subtopic_name" class="text-input" :disabled="!questionEditForm.topic_name">
                        <option value="">Not set</option>
                        <option v-for="subtopic in questionEditSubtopicOptions" :key="subtopic" :value="subtopic">
                          {{ subtopic }}
                        </option>
                      </select>
                    </label>
                  </div>

                  <div v-if="questionEditForm.question_type !== 'open_ended'" class="digitalized-options">
                    <p class="field-label">Choices (select the correct one)</p>
                    <div
                      v-for="(option, optionIndex) in questionEditForm.options"
                      :key="optionIndex"
                      class="library-question-edit-option-row"
                    >
                      <input
                        type="radio"
                        :name="`edit-question-${question.id}`"
                        :checked="option.is_correct"
                        @change="setQuestionEditCorrectOption(optionIndex)"
                      />
                      <span class="library-bank-option-label">{{ option.label }}.</span>
                      <input v-model.trim="option.text" type="text" class="text-input" />
                      <button
                        type="button"
                        class="ghost-btn icon-only"
                        title="Remove choice"
                        @click="removeQuestionEditOption(optionIndex)"
                      >
                        <X :size="14" />
                      </button>
                    </div>
                    <button
                      type="button"
                      class="ghost-btn"
                      :disabled="questionEditForm.options.length >= 8"
                      @click="addQuestionEditOption"
                    >
                      <Plus :size="14" />
                      Add Choice
                    </button>
                  </div>

                  <div class="modal-actions">
                    <button type="button" class="ghost-btn" :disabled="questionEditSaving" @click="cancelEditingQuestion">
                      Cancel
                    </button>
                    <button type="button" class="primary-btn" :disabled="questionEditSaving" @click="saveEditingQuestion">
                      <RefreshCw v-if="questionEditSaving" :size="14" class="spin-soft" />
                      <span>{{ questionEditSaving ? 'Saving...' : 'Save Question' }}</span>
                    </button>
                  </div>
                </div>
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
      <div v-if="showEditLibraryBankModal" class="modal-backdrop" @click.self="closeEditLibraryBankModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Edit Question Set</h4>
            <button type="button" class="modal-close" @click="closeEditLibraryBankModal">
              <X :size="16" />
            </button>
          </header>

          <div class="room-form-grid">
            <label class="field-stack">
              <span class="field-label">Question Name</span>
              <input v-model.trim="libraryBankEditForm.title" type="text" class="text-input" maxlength="255" />
            </label>

            <label class="field-stack">
              <span class="field-label">Subject Category</span>
              <select v-model="libraryBankEditForm.subject" class="text-input">
                <option value="">General subject</option>
                <option v-for="subject in librarySubjectCategories" :key="subject" :value="subject">
                  {{ subject }}
                </option>
              </select>
              <p class="library-upload-note">
                Changing the subject re-queues automatic mapping for every item that wasn't manually confirmed by you —
                your own confirmed tags are left untouched.
              </p>
            </label>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="libraryBankEditSaving" @click="closeEditLibraryBankModal">
              Cancel
            </button>
            <button
              type="button"
              class="primary-btn"
              :disabled="libraryBankEditSaving || !libraryBankEditForm.title.trim()"
              @click="handleUpdateLibraryBank"
            >
              <RefreshCw v-if="libraryBankEditSaving" :size="14" class="spin-soft" />
              <span>{{ libraryBankEditSaving ? 'Saving...' : 'Save Changes' }}</span>
            </button>
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
import { AlertCircle, BookOpen, CheckCircle2, Eye, FileText, Pencil, Plus, RefreshCw, Sparkles, Trash2, X } from 'lucide-vue-next'
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
  showEditLibraryBankModal,
  libraryLoading,
  libraryBankQuestionsLoading,
  librarySaving,
  libraryDeleting,
  libraryMappingSaving,
  libraryBankEditSaving,
  questionEditSaving,
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
  libraryBankEditForm,
  librarySubjectCategories,
  libraryDifficultyOptions,
  libraryCognitiveLevelOptions,
  libraryQuestionTypeOptions,
  canSaveLibraryQuestionBank,
  editingQuestionId,
  questionEditForm,
  questionEditTopicOptions,
  questionEditSubtopicOptions,
  openLibraryQuestionModal,
  closeLibraryQuestionModal,
  openLibraryBankQuestionsModal,
  closeLibraryBankQuestionsModal,
  openDeleteLibraryBankModal,
  closeDeleteLibraryBankModal,
  openEditLibraryBankModal,
  closeEditLibraryBankModal,
  handleUpdateLibraryBank,
  handleLibraryDocxChange,
  handleSaveLibraryQuestionBank,
  handleDeleteLibraryBank,
  queueBankMapping,
  confirmBankTags,
  startEditingQuestion,
  cancelEditingQuestion,
  handleQuestionEditTopicChange,
  addQuestionEditOption,
  removeQuestionEditOption,
  setQuestionEditCorrectOption,
  saveEditingQuestion,
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

.library-bank-question-actions {
  display: flex;
  justify-content: flex-end;
}

.library-question-edit-form {
  display: grid;
  gap: 12px;
  padding-top: 4px;
  border-top: 1px dashed rgba(13, 21, 71, 0.14);
}

.library-question-edit-option-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.library-question-edit-option-row .text-input {
  flex: 1;
}

.ghost-btn.icon-only {
  padding: 6px;
}
</style>
