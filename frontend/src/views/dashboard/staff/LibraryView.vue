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
        <p>Upload a DOCX to convert assessment questions and queue AI remedial generation in the background.</p>
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
                <small>AI Pending</small>
                <strong>{{ bank.remedial_pending_count ?? 0 }}</strong>
              </div>
              <div>
                <small>AI Approved</small>
                <strong>{{ bank.remedial_approved_count ?? 0 }}</strong>
              </div>
              <div>
                <small>AI Status</small>
                <strong>
                  <span class="pill" :class="resolveGenerationStatusClass(bank.remedial_generation_status)">
                    {{ formatGenerationStatus(bank.remedial_generation_status) }}
                  </span>
                </strong>
              </div>
            </div>

            <p v-if="bank.remedial_generation_message" class="muted">
              {{ bank.remedial_generation_message }}
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
              :disabled="libraryGenerationSaving || ['queued', 'processing'].includes(bank.remedial_generation_status)"
              @click="queueBankRemedialGeneration(bank.id)"
            >
              <RefreshCw :size="15" />
              Generate New AI Questions
            </button>
            <button
              type="button"
              class="danger-btn"
              :disabled="libraryDeleting || libraryGenerationSaving"
              @click="openDeleteLibraryBankModal(bank)"
            >
              <Trash2 :size="15" />
              Delete
            </button>
          </div>
        </article>
      </div>
    </article>

    <article class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <Sparkles :size="18" />
          <h3>AI Remedial Review Queue</h3>
        </div>
        <button type="button" class="ghost-btn" :disabled="libraryReviewLoading || libraryReviewSaving" @click="loadRemedialQueue">
          <RefreshCw :size="15" :class="{ 'spin-soft': libraryReviewLoading }" />
          Refresh Queue
        </button>
      </header>

      <div v-if="libraryReviewLoading && remedialQueueGroups.length === 0" class="room-empty-state compact">
        <RefreshCw :size="30" class="spin-soft" />
        <p>Loading generated remedial questions...</p>
      </div>

      <div v-else-if="remedialQueueGroups.length === 0" class="room-empty-state compact">
        <Sparkles :size="28" />
        <p>No AI remedial questions are waiting for review yet. Check each bank's AI status above for queued, failed, or skipped generation.</p>
      </div>

      <div v-else class="library-queue-bank-list">
        <section
          v-for="bankGroup in remedialQueueGroups"
          :key="bankGroup.bank.id || `queue-bank-${bankGroup.bank.title}`"
          class="library-queue-bank-card"
        >
          <header class="library-queue-bank-head">
            <div class="library-queue-bank-copy">
              <span class="library-remedial-group-kicker">Question Set</span>
              <strong>{{ bankGroup.bank.title }}</strong>
              <p>{{ bankGroup.bank.subject || 'General subject' }}</p>
            </div>

            <div class="library-queue-bank-actions">
              <div class="library-remedial-group-meta">
                <span class="pill" :class="resolveGenerationStatusClass(bankGroup.bank.remedial_generation_status)">
                  {{ formatGenerationStatus(bankGroup.bank.remedial_generation_status) }}
                </span>
                <span class="pill neutral">{{ bankGroup.statusSummary.all }} AI question(s)</span>
                <span
                  v-for="statusPill in remedialGroupStatusPills(bankGroup)"
                  :key="`${bankGroup.bank.id || bankGroup.bank.title}-${statusPill.status}`"
                  class="pill"
                  :class="statusPill.className"
                >
                  {{ statusPill.count }} {{ statusPill.label }}
                </span>
              </div>

              <div class="management-actions compact">
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="libraryReviewSaving || (bankGroup.statusSummary.pending_review ?? 0) === 0"
                  @click="updateRemedialSetStatus(bankGroup.bank.id, 'approved', 'pending_review')"
                >
                  Approve Pending Set
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="libraryReviewSaving || (bankGroup.statusSummary.pending_review ?? 0) === 0"
                  @click="updateRemedialSetStatus(bankGroup.bank.id, 'rejected', 'pending_review')"
                >
                  Reject Pending Set
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="libraryBankQuestionsLoading"
                  @click="openLibraryBankQuestionsModal(bankGroup.bank)"
                >
                  <Eye :size="15" />
                  View Set
                </button>
              </div>
            </div>
          </header>

          <div class="library-remedial-group-list queue-group-list">
            <section
              v-for="group in bankGroup.sourceGroups"
              :key="group.source_question?.id || `queue-source-${group.variants[0]?.id}`"
              class="library-remedial-group"
            >
              <div class="library-remedial-group-summary">
                <div class="library-remedial-group-copy">
                  <span class="library-remedial-group-kicker">
                    Assessment Q{{ group.source_question?.item_number || 'N/A' }}
                  </span>
                  <strong>{{ group.source_question?.question_text || 'No source question preview available.' }}</strong>
                  <small v-if="group.source_question?.answer_text" class="remedial-source-answer">
                    Source answer:
                    <span v-if="group.source_question?.answer_label">{{ group.source_question.answer_label }}.</span>
                    {{ group.source_question.answer_text }}
                  </small>
                </div>

                <div class="library-remedial-group-meta">
                  <span class="pill neutral">{{ group.variants.length }} variant(s)</span>
                  <span
                    v-for="statusPill in remedialGroupStatusPills(group)"
                    :key="`${group.source_question?.id || 'queue-group'}-${statusPill.status}`"
                    class="pill"
                    :class="statusPill.className"
                  >
                    {{ statusPill.count }} {{ statusPill.label }}
                  </span>
                </div>
              </div>

              <div class="library-remedial-variant-list">
                <article
                  v-for="question in group.variants"
                  :key="question.id"
                  class="library-bank-question-card remedial-variant-card"
                >
                  <div class="library-bank-question-head">
                    <strong>{{ question.question_text }}</strong>
                    <div class="library-bank-question-pills">
                      <span class="pill" :class="resolveReviewStatusClass(question.status)">
                        {{ formatGenerationStatus(question.status) }}
                      </span>
                      <span class="pill neutral">{{ itemStyleLabel(question.item_style) }}</span>
                      <span class="pill success">{{ question.difficulty || 'medium' }}</span>
                      <span v-if="question.cognitive_level" class="pill neutral">{{ cognitiveLevelLabel(question.cognitive_level) }}</span>
                      <span v-if="question.topic?.name" class="pill navy">{{ question.topic.name }}</span>
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

                  <div
                    v-if="question.tested_concept || question.difference_from_source"
                    class="remedial-detail-grid"
                  >
                    <div class="remedial-detail-block full-width">
                      <small>AI Notes</small>
                      <p v-if="question.tested_concept"><strong>Tested concept:</strong> {{ question.tested_concept }}</p>
                      <p v-if="question.difference_from_source"><strong>How it differs:</strong> {{ question.difference_from_source }}</p>
                    </div>
                  </div>

                  <div class="management-actions compact">
                    <button
                      type="button"
                      class="ghost-btn"
                      :disabled="libraryReviewSaving || libraryRemedialEditSaving"
                      @click="openRemedialEditModal(question)"
                    >
                      <Pencil :size="15" />
                      Edit
                    </button>
                    <button
                      type="button"
                      class="ghost-btn"
                      :disabled="libraryReviewSaving"
                      @click="refreshRemedialQuestion(question.id)"
                    >
                      <RefreshCw :size="15" />
                      Refresh
                    </button>
                    <button
                      type="button"
                      class="ghost-btn"
                      :disabled="libraryReviewSaving || question.status === 'rejected'"
                      @click="updateRemedialStatus(question.id, 'rejected')"
                    >
                      Reject
                    </button>
                    <button
                      type="button"
                      class="primary-btn"
                      :disabled="libraryReviewSaving || question.status === 'approved'"
                      @click="updateRemedialStatus(question.id, 'approved')"
                    >
                      Approve
                    </button>
                  </div>
                </article>
              </div>
            </section>
          </div>
        </section>
      </div>
    </article>

    <teleport to="body">
      <div v-if="showLibraryQuestionModal" class="modal-backdrop" @click.self="closeLibraryQuestionModal">
        <div class="modal-card library-modal-card">
          <header class="modal-head library-modal-head">
            <div class="library-modal-title-wrap">
              <h4>Add Assessment Questions</h4>
              <p>Upload your DOCX, tag optional topics, and the system will queue pending AI remedial variants after save.</p>
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
              {{ librarySaving ? 'Saving...' : 'Save And Queue Remedial Variants' }}
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
                    <span class="pill neutral">{{ question.remedial_variant_count ?? 0 }} AI variant(s)</span>
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
            <button type="button" class="ghost-btn" @click="closeLibraryBankQuestionsModal">Close</button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showRemedialEditModal" class="modal-backdrop" @click.self="closeRemedialEditModal">
        <div class="modal-card library-remedial-edit-modal">
          <header class="modal-head library-modal-head">
            <div class="library-modal-title-wrap">
              <h4>Edit AI Remedial Question</h4>
              <p>
                {{ selectedRemedialQuestion?.question_bank?.title || selectedLibraryQuestionBank?.title || 'Question Set' }}
                <span v-if="selectedRemedialQuestion?.source_question?.item_number">
                  • Source Q{{ selectedRemedialQuestion.source_question.item_number }}
                </span>
              </p>
            </div>
            <button type="button" class="modal-close" @click="closeRemedialEditModal">
              <X :size="16" />
            </button>
          </header>

          <div class="library-bank-view-body">
            <div v-if="selectedRemedialQuestion?.source_question" class="remedial-edit-source-card">
              <small>Source Question</small>
              <strong>{{ selectedRemedialQuestion.source_question.question_text }}</strong>
              <p v-if="selectedRemedialQuestion.source_question.answer_text">
                Source answer:
                <span v-if="selectedRemedialQuestion.source_question.answer_label">{{ selectedRemedialQuestion.source_question.answer_label }}.</span>
                {{ selectedRemedialQuestion.source_question.answer_text }}
              </p>
            </div>

            <label class="field-stack">
              <span class="field-label">Generated Question</span>
              <textarea v-model.trim="remedialEditForm.questionText" class="text-input remedial-textarea" rows="3" />
            </label>

            <div class="library-edit-grid">
              <label class="field-stack">
                <span class="field-label">Item Style</span>
                <select v-model="remedialEditForm.itemStyle" class="text-input">
                  <option v-for="style in remedialItemStyleOptions" :key="style" :value="style">
                    {{ itemStyleLabel(style) }}
                  </option>
                </select>
              </label>

              <label class="field-stack">
                <span class="field-label">Difficulty</span>
                <select v-model="remedialEditForm.difficulty" class="text-input">
                  <option v-for="difficulty in remedialDifficultyOptions" :key="difficulty" :value="difficulty">
                    {{ questionTypeLabel(difficulty) }}
                  </option>
                </select>
              </label>

              <label class="field-stack">
                <span class="field-label">Correct Answer</span>
                <select v-model="remedialEditForm.correctAnswer" class="text-input">
                  <option value="A">A</option>
                  <option value="B">B</option>
                  <option value="C">C</option>
                  <option value="D">D</option>
                </select>
              </label>
            </div>

            <div v-if="remedialEditForm.cognitiveLevel || remedialEditForm.testedConcept || remedialEditForm.differenceFromSource" class="remedial-edit-source-card">
              <small>AI Notes</small>
              <p v-if="remedialEditForm.cognitiveLevel"><strong>Cognitive level:</strong> {{ remedialEditForm.cognitiveLevel }}</p>
              <p v-if="remedialEditForm.testedConcept"><strong>Tested concept:</strong> {{ remedialEditForm.testedConcept }}</p>
              <p v-if="remedialEditForm.differenceFromSource"><strong>How it differs:</strong> {{ remedialEditForm.differenceFromSource }}</p>
            </div>

            <div class="library-edit-grid four-up">
              <label v-for="label in ['A', 'B', 'C', 'D']" :key="`option-${label}`" class="field-stack">
                <span class="field-label">Option {{ label }}</span>
                <input v-model.trim="remedialEditForm.options[label]" type="text" class="text-input" />
              </label>
            </div>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="libraryRemedialEditSaving" @click="closeRemedialEditModal">Cancel</button>
            <button
              type="button"
              class="primary-btn"
              :disabled="!canSaveRemedialEdit"
              @click="saveRemedialQuestionEdits"
            >
              <RefreshCw v-if="libraryRemedialEditSaving" :size="16" class="spin-soft" />
              <Pencil v-else :size="16" />
              {{ libraryRemedialEditSaving ? 'Saving...' : 'Save Changes' }}
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
            Delete <strong>{{ selectedLibraryBank?.title }}</strong>? This removes both assessment and remedial questions linked to the bank.
          </p>
          <p class="muted">
            This action is blocked if the set already has official exam or practice attempt records.
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

function itemStyleLabel(style) {
  return questionTypeLabel(style || 'reverse_association')
}

function cognitiveLevelLabel(level) {
  return questionTypeLabel(level || 'comprehension')
}

function resolveReviewStatusClass(status) {
  const normalized = String(status ?? 'pending_review').toLowerCase()

  if (normalized === 'approved') return 'success'
  if (normalized === 'rejected') return 'danger'
  return 'neutral'
}

function remedialGroupStatusPills(group) {
  const variants = Array.isArray(group?.variants)
    ? group.variants
    : (Array.isArray(group?.questions) ? group.questions : [])
  const counts = variants.reduce((summary, variant) => {
    const status = String(variant?.status ?? 'pending_review').toLowerCase()
    summary[status] = (summary[status] ?? 0) + 1
    return summary
  }, {})

  return [
    { status: 'pending_review', label: 'pending', className: 'neutral', count: counts.pending_review ?? 0 },
    { status: 'approved', label: 'approved', className: 'success', count: counts.approved ?? 0 },
    { status: 'rejected', label: 'rejected', className: 'danger', count: counts.rejected ?? 0 },
  ].filter((pill) => pill.count > 0)
}

const {
  showLibraryQuestionModal,
  showLibraryBankQuestionsModal,
  showDeleteLibraryBankModal,
  showRemedialEditModal,
  libraryLoading,
  libraryReviewLoading,
  libraryBankQuestionsLoading,
  librarySaving,
  libraryDeleting,
  libraryReviewSaving,
  libraryGenerationSaving,
  libraryRemedialEditSaving,
  libraryError,
  libraryMessage,
  libraryParsing,
  libraryParseError,
  libraryDocxName,
  libraryFileInputKey,
  libraryQuestionBanks,
  selectedLibraryBank,
  selectedLibraryQuestionBank,
  selectedRemedialQuestion,
  groupedLibraryPreviewWarnings,
  digitalizedQuestions,
  libraryBankQuestions,
  libraryBankRemedialQuestions,
  libraryBankQuestionViewTab,
  libraryBankRemedialStatusFilter,
  libraryBankRemedialStatusSummary,
  libraryBankRemedialGroups,
  remedialQueueGroups,
  libraryForm,
  remedialEditForm,
  librarySubjectCategories,
  remedialItemStyleOptions,
  remedialDifficultyOptions,
  canSaveLibraryQuestionBank,
  canSaveRemedialEdit,
  openLibraryQuestionModal,
  closeLibraryQuestionModal,
  openLibraryBankQuestionsModal,
  closeLibraryBankQuestionsModal,
  openRemedialEditModal,
  closeRemedialEditModal,
  setLibraryBankQuestionViewTab,
  setLibraryBankRemedialStatusFilter,
  openDeleteLibraryBankModal,
  closeDeleteLibraryBankModal,
  handleLibraryDocxChange,
  loadRemedialQueue,
  handleSaveLibraryQuestionBank,
  handleDeleteLibraryBank,
  queueBankRemedialGeneration,
  updateRemedialStatus,
  updateRemedialSetStatus,
  refreshRemedialQuestion,
  saveRemedialQuestionEdits,
} = useLibraryModule()
</script>

<style scoped src="../dashboard.css"></style>
<style scoped>
.library-bank-view-modal {
  width: min(1120px, calc(100vw - 32px));
}

.library-remedial-edit-modal {
  width: min(920px, calc(100vw - 32px));
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

.library-bank-tab-row,
.library-remedial-filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.library-bank-tab-row .ghost-btn.active,
.library-remedial-filter-row .ghost-btn.active {
  border-color: rgba(13, 21, 71, 0.28);
  background: rgba(13, 21, 71, 0.08);
  color: var(--lnu-navy-deep);
}

.library-bank-question-list {
  display: grid;
  gap: 14px;
  max-height: min(70vh, 760px);
  overflow-y: auto;
  padding-right: 4px;
}

.library-queue-bank-list {
  display: grid;
  gap: 16px;
}

.library-queue-bank-card {
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 18px;
  background: linear-gradient(180deg, #ffffff 0%, rgba(248, 250, 255, 0.96) 100%);
  padding: 18px;
  display: grid;
  gap: 14px;
}

.library-queue-bank-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.library-queue-bank-copy {
  display: grid;
  gap: 6px;
}

.text-danger {
  color: #b91c1c;
}

.library-queue-bank-copy strong {
  color: var(--lnu-navy-deep);
  font-size: 18px;
  line-height: 1.35;
}

.library-queue-bank-copy p {
  margin: 0;
  color: var(--lnu-text-muted);
}

.library-queue-bank-actions {
  display: grid;
  gap: 10px;
  justify-items: flex-end;
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

.remedial-source-answer {
  color: var(--lnu-text-muted);
  line-height: 1.45;
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

.library-remedial-group-list {
  display: grid;
  gap: 12px;
  max-height: min(70vh, 760px);
  overflow-y: auto;
  padding-right: 4px;
}

.library-remedial-group {
  border: 1px solid rgba(13, 21, 71, 0.1);
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.98);
}

.library-remedial-group-summary {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  padding: 16px;
}

.library-remedial-group-copy {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.library-remedial-group-copy strong {
  color: var(--lnu-navy-deep);
  line-height: 1.45;
}

.library-remedial-group-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--lnu-text-muted);
}

.library-remedial-group-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}

.library-remedial-variant-list {
  border-top: 1px solid rgba(13, 21, 71, 0.08);
  padding: 0 16px 16px;
  display: grid;
  gap: 12px;
}

.remedial-variant-card {
  background: linear-gradient(180deg, rgba(250, 251, 255, 0.98), rgba(245, 247, 252, 0.94));
}

.remedial-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.remedial-detail-block {
  border-radius: 12px;
  background: rgba(13, 21, 71, 0.04);
  padding: 12px;
  display: grid;
  gap: 6px;
}

.remedial-detail-block.full-width {
  grid-column: 1 / -1;
}

.remedial-detail-block small,
.remedial-edit-source-card small {
  color: var(--lnu-text-muted);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.remedial-detail-block p,
.remedial-edit-source-card p {
  margin: 0;
  color: var(--lnu-text);
  line-height: 1.5;
}

.remedial-edit-source-card {
  border-radius: 14px;
  background: rgba(13, 21, 71, 0.04);
  padding: 14px;
  display: grid;
  gap: 8px;
}

.remedial-edit-source-card strong {
  color: var(--lnu-navy-deep);
  line-height: 1.45;
}

.library-edit-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.library-edit-grid.four-up {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.remedial-textarea {
  min-height: 104px;
  resize: vertical;
}

.queue-group-list {
  max-height: none;
  overflow: visible;
  padding-right: 0;
}

@media (max-width: 900px) {
  .library-queue-bank-head,
  .library-remedial-group-summary {
    flex-direction: column;
  }

  .library-queue-bank-actions {
    justify-items: flex-start;
  }

  .library-remedial-group-meta {
    justify-content: flex-start;
  }

  .remedial-detail-grid,
  .library-edit-grid,
  .library-edit-grid.four-up {
    grid-template-columns: 1fr;
  }
}
</style>
