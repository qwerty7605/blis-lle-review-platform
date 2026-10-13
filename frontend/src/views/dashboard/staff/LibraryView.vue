<template>
  <section ref="pageRef" class="library-view">
    <div v-if="libraryMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ libraryMessage }}</span>
    </div>
    <div v-if="libraryError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ libraryError }}</span>
    </div>

    <!-- What this page is, the totals, and the one action that adds to it -->
    <header class="library-hero">
      <div class="library-hero-copy">
        <h2 class="library-hero-title">Assessment Library</h2>
        <p class="library-hero-body">
          Your question banks. Upload a DOCX and its questions are saved here, ready to build exams from.
        </p>

        <ul v-if="libraryQuestionBanks.length > 0" class="library-hero-stats">
          <li><Layers :size="13" />{{ libraryQuestionBanks.length }} {{ libraryQuestionBanks.length === 1 ? 'bank' : 'banks' }}</li>
          <li><ListChecks :size="13" />{{ totalQuestions }} questions</li>
          <li><BookOpen :size="13" />{{ subjectFilters.length - 1 }} {{ subjectFilters.length - 1 === 1 ? 'subject' : 'subjects' }}</li>
        </ul>
      </div>

      <button type="button" class="library-hero-btn" @click="openLibraryQuestionModal">
        <Plus :size="16" />
        Add Questions
      </button>
    </header>

    <div v-if="libraryLoading && libraryQuestionBanks.length === 0" class="library-state">
      <RefreshCw :size="30" class="spin-soft" />
      <strong>Loading question banks</strong>
      <p>Please wait while we fetch your library entries.</p>
    </div>

    <div v-else-if="libraryQuestionBanks.length === 0" class="library-state">
      <span class="library-state-icon"><BookOpen :size="24" /></span>
      <strong>No question banks yet</strong>
      <p>Upload a DOCX of numbered questions. They are converted and tagged by topic for you.</p>
      <button type="button" class="primary-btn" @click="openLibraryQuestionModal">
        <Plus :size="16" />
        Add Questions
      </button>
    </div>

    <template v-else>
      <!-- Find a bank: by name, or by subject -->
      <div class="library-toolbar">
        <label class="library-search">
          <Search :size="16" class="library-search-icon" />
          <input
            v-model.trim="bankSearch"
            type="search"
            class="library-search-input"
            placeholder="Search by name or file"
            aria-label="Search question banks"
          />
        </label>

        <OwnerFilter v-if="seesEveryTeacher" v-model="selectedOwnerFilter" :owners="bankOwners" label="Files by" />

        <div v-scroll-hint class="library-filters" role="group" aria-label="Filter by subject">
          <button
            v-for="filter in subjectFilters"
            :key="filter.key"
            type="button"
            class="library-filter"
            :class="{ active: activeSubject === filter.key }"
            :aria-pressed="activeSubject === filter.key"
            @click="activeSubject = filter.key"
          >
            {{ filter.label }}
            <span class="library-filter-count">{{ filter.count }}</span>
          </button>
        </div>
      </div>

      <div v-if="visibleBanks.length === 0" class="library-state">
        <span class="library-state-icon"><Search :size="22" /></span>
        <strong>No banks match</strong>
        <p>Try a different search or subject.</p>
        <button type="button" class="ghost-btn" @click="clearBankFilters">Clear filters</button>
      </div>

      <!-- A table where its six columns fit. Anywhere narrower (a phone, a
           tablet, a small laptop with the sidebar open) the cards below. -->
      <div v-else-if="!useCards" class="library-table-card">
        <div class="library-table-scroll">
          <table class="library-table">
            <thead>
              <tr>
                <th scope="col">Question bank</th>
                <th scope="col" class="is-number">Questions</th>
                <th scope="col">Source file</th>
                <th scope="col">Updated</th>
                <th scope="col">Topics</th>
                <th scope="col" class="is-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="bank in visibleBanks" :key="bank.id">
                <td>
                  <div class="bank-cell">
                    <span class="bank-icon" aria-hidden="true"><FileText :size="17" /></span>
                    <div class="bank-cell-copy">
                      <strong>{{ bank.title }}</strong>
                      <span>{{ bank.subject || 'General subject' }}</span>
                      <span v-if="seesEveryTeacher && bank.creator?.name" class="owner-line">By {{ bank.creator.name }}</span>
                    </div>
                  </div>
                </td>
                <td class="is-number">
                  <strong class="bank-number">{{ bank.total_items }}</strong>
                </td>
                <td>
                  <span class="bank-file" :title="bank.source_filename || 'Manual entry'">
                    {{ bank.source_filename || 'Manual entry' }}
                  </span>
                </td>
                <td class="is-date" :title="formatDateTime(bank.updated_at)">{{ formatCompactDate(bank.updated_at) }}</td>
                <td>
                  <div class="bank-topics">
                    <span class="bank-topics-status" :class="`is-${mappingState(bank).tone}`" :title="mappingState(bank).detail">
                      <component
                        :is="mappingState(bank).icon"
                        :size="14"
                        :class="{ 'spin-soft': mappingState(bank).busy }"
                      />
                      {{ mappingState(bank).label }}
                    </span>
                    <button
                      type="button"
                      class="bank-topics-btn"
                      :disabled="libraryMappingSaving || mappingState(bank).busy"
                      @click="queueBankMapping(bank.id)"
                    >
                      {{ mappingState(bank).action }}
                    </button>
                  </div>
                </td>
                <td class="is-actions">
                  <div class="bank-row-actions">
                    <button
                      type="button"
                      class="primary-btn"
                      :disabled="libraryBankQuestionsLoading"
                      @click="openLibraryBankQuestionsModal(bank)"
                    >
                      <Eye :size="14" />
                      View
                    </button>
                    <button type="button" class="ghost-btn" @click="openEditLibraryBankModal(bank)">
                      <Pencil :size="14" />
                      Edit
                    </button>
                    <button
                      type="button"
                      class="bank-delete-btn"
                      :disabled="libraryDeleting"
                      :aria-label="`Delete ${bank.title}`"
                      title="Delete"
                      @click="openDeleteLibraryBankModal(bank)"
                    >
                      <Trash2 :size="15" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <ul v-else class="library-grid">
        <li v-for="bank in visibleBanks" :key="bank.id" class="bank-card">
          <div class="bank-card-top">
            <span class="bank-icon" aria-hidden="true"><FileText :size="18" /></span>
            <div class="bank-card-heading">
              <h3 class="bank-title">{{ bank.title }}</h3>
              <p class="bank-subject">
                {{ bank.subject || 'General subject' }}<template v-if="seesEveryTeacher && bank.creator?.name"> &middot; By {{ bank.creator.name }}</template>
              </p>
            </div>
            <span class="bank-count">
              <strong>{{ bank.total_items }}</strong>
              {{ bank.total_items === 1 ? 'question' : 'questions' }}
            </span>
          </div>

          <dl class="bank-facts">
            <div>
              <dt>Source file</dt>
              <dd :title="bank.source_filename || 'Manual entry'">{{ bank.source_filename || 'Manual entry' }}</dd>
            </div>
            <div>
              <dt>Updated</dt>
              <dd>{{ formatDateTime(bank.updated_at) }}</dd>
            </div>
          </dl>

          <!-- Whether the questions have been tagged by topic yet -->
          <div class="bank-mapping" :class="`is-${mappingState(bank).tone}`">
            <component
              :is="mappingState(bank).icon"
              :size="15"
              :class="{ 'spin-soft': mappingState(bank).busy }"
            />
            <div class="bank-mapping-copy">
              <strong>{{ mappingState(bank).label }}</strong>
              <span>{{ mappingState(bank).detail }}</span>
            </div>
            <button
              type="button"
              class="bank-mapping-btn"
              :disabled="libraryMappingSaving || mappingState(bank).busy"
              @click="queueBankMapping(bank.id)"
            >
              <Sparkles :size="14" />
              {{ mappingState(bank).action }}
            </button>
          </div>

          <div class="bank-actions">
            <button
              type="button"
              class="primary-btn bank-view-btn"
              :disabled="libraryBankQuestionsLoading"
              @click="openLibraryBankQuestionsModal(bank)"
            >
              <Eye :size="15" />
              View Questions
            </button>
            <button type="button" class="ghost-btn" @click="openEditLibraryBankModal(bank)">
              <Pencil :size="15" />
              Edit
            </button>
            <button
              type="button"
              class="bank-delete-btn"
              :disabled="libraryDeleting"
              :aria-label="`Delete ${bank.title}`"
              title="Delete"
              @click="openDeleteLibraryBankModal(bank)"
            >
              <Trash2 :size="16" />
            </button>
          </div>
        </li>
      </ul>
    </template>

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
                    <span v-if="question.difficulty" class="pill pill-cap success">{{ question.difficulty }}</span>
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

          <!-- The page-level banner sits behind this dialog, so a refused
               delete has to be reported here to be seen at all. -->
          <div v-if="libraryError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ libraryError }}</span>
          </div>

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
import { computed, ref } from 'vue'
import {
  AlertCircle,
  BookOpen,
  CheckCircle2,
  CircleDashed,
  Eye,
  FileText,
  Layers,
  ListChecks,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Sparkles,
  Trash2,
  X,
} from 'lucide-vue-next'
import { formatCompactDate, formatDateTime } from '../composables/useDashboardFormatters'
import { estimatePageWidth, useElementWidth, usePhoneLayout } from '../composables/useViewport'
import { useLibraryModule } from '../composables/useLibraryModule'
import { vScrollHint } from '@/utils/scrollHint'
import { useAuthStore } from '@/store/auth.store'
import { isOwnedBy, ownersOf, overseesAll } from '@/utils/owners'
import OwnerFilter from '../shared/OwnerFilter.vue'

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

/*
 * Finding a bank. Filtered in the browser: a teacher has tens of banks, and
 * /library/banks already returns all of them.
 */
// Phones (640px and under) show the banks as cards; wider screens as a table.
/*
 * The table needs about 940px. Whether the list has that depends on the
 * page's own width, not the window's: at 768px and at 1024px with the
 * sidebar open it has about 730px, and View, Edit and Delete ended up off
 * the right-hand edge. So the page is measured.
 */
const LIBRARY_TABLE_MIN_WIDTH = 940
const isPhone = usePhoneLayout()
const pageRef = ref(null)
const pageWidth = useElementWidth(pageRef, estimatePageWidth())
const useCards = computed(() => (
  pageWidth.value > 0 ? pageWidth.value < LIBRARY_TABLE_MIN_WIDTH : isPhone.value
))

const bankSearch = ref('')
const activeSubject = ref('all')

// An administrator's Library holds every teacher's files.
const seesEveryTeacher = computed(() => overseesAll(useAuthStore().user))
const selectedOwnerFilter = ref('')
const bankOwners = computed(() => ownersOf(libraryQuestionBanks.value))

const totalQuestions = computed(() => (
  libraryQuestionBanks.value.reduce((sum, bank) => sum + Number(bank.total_items ?? 0), 0)
))

function bankSubject(bank) {
  return bank.subject || 'General subject'
}

// "All" first, then each subject that actually has a bank, alphabetically.
const subjectFilters = computed(() => {
  const counts = new Map()

  libraryQuestionBanks.value.forEach((bank) => {
    counts.set(bankSubject(bank), (counts.get(bankSubject(bank)) ?? 0) + 1)
  })

  return [
    { key: 'all', label: 'All', count: libraryQuestionBanks.value.length },
    ...[...counts.entries()]
      .sort(([left], [right]) => left.localeCompare(right))
      .map(([subject, count]) => ({ key: subject, label: subject, count })),
  ]
})

const visibleBanks = computed(() => {
  const needle = bankSearch.value.toLowerCase()

  return libraryQuestionBanks.value.filter((bank) => {
    if (!isOwnedBy(bank, selectedOwnerFilter.value)) return false
    if (activeSubject.value !== 'all' && bankSubject(bank) !== activeSubject.value) return false
    if (!needle) return true

    return [bank.title, bank.source_filename, bank.subject]
      .some((value) => String(value ?? '').toLowerCase().includes(needle))
  })
})

function clearBankFilters() {
  bankSearch.value = ''
  activeSubject.value = 'all'
  selectedOwnerFilter.value = ''
}

/*
 * "Item mapping" in plain words: whether each question has been tagged with
 * its topic, difficulty and thinking level yet, and what the button will do.
 */
function mappingState(bank) {
  const status = String(bank.mapping_status ?? 'idle').toLowerCase()

  if (status === 'completed') {
    return {
      tone: 'done',
      icon: CheckCircle2,
      label: 'Topics tagged',
      detail: 'Every question has a topic, difficulty and thinking level.',
      action: 'Tag again',
    }
  }

  if (status === 'queued' || status === 'processing') {
    return {
      tone: 'busy',
      icon: RefreshCw,
      busy: true,
      label: status === 'queued' ? 'Waiting to tag' : 'Tagging topics...',
      detail: 'This runs in the background. You can keep working.',
      action: 'Tagging',
    }
  }

  if (status === 'failed' || status === 'skipped') {
    return {
      tone: 'failed',
      icon: AlertCircle,
      label: 'Tagging did not finish',
      detail: bank.mapping_message || 'Try again in a moment.',
      action: 'Try again',
    }
  }

  return {
    tone: 'idle',
    icon: CircleDashed,
    label: 'Not tagged yet',
    detail: 'Tag the questions so analytics can show weak topics.',
    action: 'Tag topics',
  }
}
</script>

<style scoped src="../dashboard.css"></style>
<style scoped>
.management-meta-grid .pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

/* A head, one list that scrolls, and the buttons under it, all inside the
   screen. The list used to have its own 70vh window inside a card with no
   height limit, which put Close below the bottom of a 768px-high laptop. */
.library-bank-view-modal {
  width: min(1120px, calc(100vw - 32px));
  max-height: calc(100vh - 32px);
  max-height: calc(100dvh - 32px);
  grid-template-rows: auto minmax(0, 1fr) auto;
}

.library-bank-view-body {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  display: grid;
  align-content: start;
  gap: 16px;
  padding-right: 4px;
}

.library-bank-view-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.library-bank-question-list {
  display: grid;
  gap: 14px;
}

.library-bank-question-card {
  border: 1px solid rgba(13, 21, 71, 0.1);
  border-radius: 20px;
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
  border-radius: 14px;
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

/* =====================================================================
   Library list: a navy header with the totals, a search and subject
   filter, then one card per question bank. Same visual language as the
   rest of the dashboard (see the BRAND LAYER in dashboard.css).
   ===================================================================== */
.library-view {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  /* start: the shared rule gives this page a full-height grid, which would
     otherwise stretch the header and cards to fill a tall screen. */
  align-content: start;
  gap: 16px;
}

/* ---------- Header ---------- */
.library-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  flex-wrap: wrap;
  padding: 26px 28px;
  border-radius: 22px;
  color: #fff;
  background: var(--panel-navy);
  box-shadow: var(--shadow-md);
}

.library-hero-copy {
  flex: 1 1 340px;
  min-width: 0;
}

.library-hero-title {
  margin: 0;
  font-size: clamp(20px, 2.2vw, 26px);
  font-weight: 600;
  line-height: 1.25;
}

.library-hero-body {
  margin: 6px 0 0;
  max-width: 60ch;
  font-size: 14px;
  line-height: 1.6;
  color: rgba(255, 255, 255, 0.82);
}

.library-hero-stats {
  list-style: none;
  margin: 14px 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.library-hero-stats li {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 11px;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.16);
  background: rgba(255, 255, 255, 0.07);
  font-size: 12px;
  font-weight: 500;
}

.library-hero-stats svg {
  color: var(--lnu-gold-light);
}

.library-hero-btn {
  flex-shrink: 0;
  height: 44px;
  padding: 0 18px;
  border: none;
  border-radius: 10px;
  background: var(--lnu-gold-light);
  color: var(--lnu-navy-deep);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  transition: transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
}

.library-hero-btn:hover {
  background: #f6dc9a;
  box-shadow: 0 10px 18px rgba(0, 0, 0, 0.18);
  transform: translateY(-1px);
}

.library-hero-btn:focus-visible {
  outline: 2px solid var(--lnu-gold-light);
  outline-offset: 2px;
}

/* ---------- Loading / empty ---------- */
.library-state {
  display: grid;
  justify-items: center;
  gap: 10px;
  padding: 48px 20px;
  border-radius: 20px;
  border: 1px dashed rgba(26, 35, 126, 0.16);
  background: var(--surface);
  color: var(--lnu-text-muted);
  text-align: center;
}

.library-state strong {
  font-size: 16px;
  font-weight: 600;
  color: #0b0d17;
}

.library-state p {
  margin: 0;
  max-width: 44ch;
  font-size: 13.5px;
  line-height: 1.6;
}

.library-state-icon {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: rgba(26, 35, 126, 0.08);
  color: var(--lnu-navy);
}

/* ---------- Search and subject filter ---------- */
/* Search on its own line, subjects under it: side by side, six subject
   chips wrapped to three lines next to a single search box. */
.library-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 10px;
}

.library-search {
  position: relative;
  width: 100%;
  max-width: 380px;
}

.library-search-icon {
  position: absolute;
  left: 13px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lnu-navy-light);
  opacity: 0.8;
  pointer-events: none;
}

.library-search-input {
  width: 100%;
  height: 42px;
  padding: 0 12px 0 38px;
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  background: var(--surface);
  font-size: 13.5px;
  color: var(--lnu-text);
  box-shadow: 0 1px 2px rgba(13, 21, 71, 0.05);
}

.library-search-input:focus {
  outline: none;
  border-color: var(--lnu-navy);
  box-shadow: var(--focus-ring);
}

.library-filters {
  flex: 1;
  min-width: 0;
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.library-filter {
  height: 36px;
  padding: 0 12px;
  border: 1px solid var(--border-soft);
  border-radius: 999px;
  background: var(--surface);
  color: var(--lnu-text-muted);
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}

.library-filter:hover:not(.active) {
  border-color: rgba(26, 35, 126, 0.3);
  color: var(--lnu-navy);
}

.library-filter.active {
  border-color: var(--lnu-navy);
  background: var(--lnu-navy);
  color: var(--lnu-gold-light);
  font-weight: 600;
}

.library-filter-count {
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.1);
  font-size: 11px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.library-filter.active .library-filter-count {
  background: rgba(255, 255, 255, 0.2);
}

/* ---------- Bank table (desktop and tablet) ---------- */
.library-table-card {
  border-radius: 20px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

/* The table keeps a readable minimum width and scrolls inside its card on a
   narrow tablet, rather than squeezing six columns into unreadable slivers. */
.library-table-scroll {
  overflow-x: auto;
}

.library-table {
  width: 100%;
  min-width: 860px;
  border-collapse: collapse;
  font-size: 13.5px;
}

.library-table th,
.library-table td {
  padding: 14px 16px;
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid var(--border-soft);
}

.library-table thead th {
  padding-top: 13px;
  padding-bottom: 13px;
  background: var(--surface-muted);
  border-bottom-color: var(--border-strong);
  font-size: 12px;
  font-weight: 600;
  color: var(--lnu-text-muted);
  white-space: nowrap;
}

.library-table tbody tr:last-child td {
  border-bottom: none;
}

.library-table tbody tr:hover td {
  background: rgba(26, 35, 126, 0.03);
}

.library-table .is-number {
  text-align: right;
}

.library-table .is-date {
  white-space: nowrap;
}

/* The name is what a teacher scans for, so it gets the spare width. */
.library-table th:first-child,
.library-table td:first-child {
  width: 36%;
}

.library-table .is-actions {
  text-align: right;
  white-space: nowrap;
}

.bank-cell {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.bank-cell .bank-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
}

.bank-cell-copy {
  min-width: 0;
  display: grid;
  gap: 1px;
}

.bank-cell-copy strong {
  font-size: 14px;
  font-weight: 600;
  line-height: 1.35;
  color: #0b0d17;
  overflow-wrap: anywhere;
}

.bank-cell-copy span {
  font-size: 12.5px;
  color: var(--lnu-text-muted);
}

.bank-number {
  font-size: 16px;
  font-weight: 600;
  color: var(--lnu-navy);
}

.bank-file {
  display: block;
  max-width: 150px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--lnu-text);
}

.bank-topics {
  display: grid;
  justify-items: start;
  gap: 3px;
}

.bank-topics-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 500;
  color: var(--lnu-text);
  white-space: nowrap;
}

.bank-topics-status svg {
  flex-shrink: 0;
  color: var(--lnu-text-muted);
}

.bank-topics-status.is-done svg { color: var(--lnu-success); }
.bank-topics-status.is-busy svg { color: var(--lnu-navy); }
.bank-topics-status.is-failed svg { color: var(--lnu-danger); }

/* A text link, not another boxed button: the row already has three. */
.bank-topics-btn {
  padding: 0;
  border: none;
  background: transparent;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--lnu-navy-light);
}

.bank-topics-btn:hover:not(:disabled) {
  text-decoration: underline;
}

.bank-topics-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.bank-topics-btn:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
  border-radius: 4px;
}

.bank-row-actions {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.bank-row-actions .primary-btn,
.bank-row-actions .ghost-btn {
  height: 34px;
  padding: 0 12px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}

.bank-row-actions .bank-delete-btn {
  width: 34px;
  height: 34px;
}

/* ---------- Bank cards (phones) ---------- */
.library-grid {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  /* One column on a phone, two where two 340px cards fit side by side. */
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr));
  gap: 14px;
}

/* Each card is a column so the action row sits at the bottom of every card
   in a row, whatever the title length. */
.bank-card {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 20px;
  border-radius: 20px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
}

.bank-card-top {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.bank-icon {
  flex-shrink: 0;
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(26, 35, 126, 0.08);
  color: var(--lnu-navy);
}

.bank-card-heading {
  flex: 1;
  min-width: 0;
}

.bank-title {
  margin: 0;
  font-size: 15.5px;
  font-weight: 600;
  line-height: 1.35;
  color: #0b0d17;
  overflow-wrap: anywhere;
}

.bank-subject {
  margin: 2px 0 0;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.bank-count {
  flex-shrink: 0;
  display: grid;
  justify-items: end;
  font-size: 11.5px;
  color: var(--lnu-text-muted);
}

.bank-count strong {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.1;
  color: var(--lnu-navy);
}

.bank-facts {
  margin: 0;
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
  gap: 10px;
}

.bank-facts > div {
  min-width: 0;
}

.bank-facts dt {
  font-size: 11.5px;
  font-weight: 500;
  color: var(--lnu-text-muted);
}

.bank-facts dd {
  margin: 2px 0 0;
  font-size: 13px;
  font-weight: 500;
  color: var(--lnu-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bank-mapping {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid var(--border-soft);
  background: var(--surface-muted);
  color: var(--lnu-text-muted);
}

.bank-mapping > svg {
  flex-shrink: 0;
}

.bank-mapping.is-done > svg { color: var(--lnu-success); }
.bank-mapping.is-busy > svg { color: var(--lnu-navy); }
.bank-mapping.is-failed > svg { color: var(--lnu-danger); }

.bank-mapping-copy {
  flex: 1;
  min-width: 0;
  display: grid;
  gap: 1px;
}

.bank-mapping-copy strong {
  font-size: 13px;
  font-weight: 600;
  color: #0b0d17;
}

.bank-mapping-copy span {
  font-size: 12px;
  line-height: 1.45;
}

.bank-mapping-btn {
  flex-shrink: 0;
  height: 34px;
  padding: 0 12px;
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  background: var(--surface);
  color: var(--lnu-navy);
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 600;
  white-space: nowrap;
}

.bank-mapping-btn:hover:not(:disabled) {
  border-color: var(--lnu-navy);
}

.bank-mapping-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.bank-actions {
  margin-top: auto;
  display: flex;
  align-items: center;
  gap: 8px;
}

.bank-view-btn {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
}

.bank-actions .ghost-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}

/* Quiet until hovered: deleting is rare, and a filled red button on every
   card made it the loudest thing on the page. */
.bank-delete-btn {
  flex-shrink: 0;
  width: var(--control-height);
  height: var(--control-height);
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  background: var(--surface);
  color: var(--lnu-text-muted);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}

.bank-delete-btn:hover:not(:disabled) {
  border-color: rgba(198, 40, 40, 0.4);
  background: rgba(198, 40, 40, 0.08);
  color: var(--lnu-danger);
}

.bank-delete-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.bank-mapping-btn:focus-visible,
.bank-delete-btn:focus-visible,
.library-filter:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

/* ---------- Narrower screens ---------- */
@media (max-width: 640px) {
  .library-hero {
    padding: 20px 18px;
    border-radius: 20px;
  }

  .library-hero-btn {
    width: 100%;
  }

  .library-search {
    max-width: none;
  }

  /* One swipeable row of subjects instead of three wrapped lines of chips. */
  .library-filters {
    flex-wrap: nowrap;
    overflow-x: auto;
    scrollbar-width: none;
    padding-bottom: 2px;
  }

  .library-filters::-webkit-scrollbar {
    display: none;
  }

  .library-filter {
    height: 40px;
  }

  .bank-card {
    padding: 18px 16px;
    border-radius: 18px;
  }

  .bank-facts {
    grid-template-columns: minmax(0, 1fr);
  }

  .bank-mapping {
    flex-wrap: wrap;
  }

  .bank-mapping-btn {
    width: 100%;
    height: 40px;
    justify-content: center;
  }

  .bank-actions {
    flex-wrap: wrap;
  }

  .bank-view-btn {
    flex: 1 1 100%;
  }

  .bank-actions .ghost-btn {
    flex: 1;
    justify-content: center;
  }
}

/* Touch screens: a search field under 16px makes iOS zoom the whole page in
   when it is tapped. */
@media (pointer: coarse) {
  .library-search-input {
    height: 44px;
    font-size: 16px;
  }

  .library-filter {
    height: 40px;
  }

  .bank-topics-btn {
    min-height: 36px;
  }

  .bank-mapping-btn {
    min-height: 40px;
  }
}

/* =====================================================================
   Dialogs: Add questions, View questions
   ===================================================================== */

/* Head, body and button bar run edge to edge. The shared .modal-card padding
   had put a white frame around all three. */
.library-modal-card {
  padding: 0;
  gap: 0;
}

@media (max-width: 640px) {
  /* Both rise from the bottom edge like every other dialog on a phone. */
  .library-modal-card,
  .library-bank-view-modal {
    width: 100%;
    margin: auto 0 0;
    border: 0;
    border-radius: 18px 18px 0 0;
  }

  .library-modal-card {
    max-height: calc(100vh - 12px);
    max-height: calc(100dvh - 12px);
  }

  /* View questions takes the whole sheet, edge to edge. */
  .library-bank-view-modal {
    height: calc(100vh - 12px);
    height: calc(100dvh - 12px);
    max-height: none;
    padding: 0;
    gap: 0;
    overflow: hidden;
  }

  .library-bank-view-modal > .library-modal-head {
    position: static;
    padding: 10px 8px 10px 16px;
  }

  .library-modal-title-wrap h4 {
    font-size: 17px;
    line-height: 1.3;
  }

  .library-modal-title-wrap p {
    margin-top: 2px;
    font-size: 13px;
  }

  .library-bank-view-body {
    gap: 12px;
    padding: 12px;
  }

  .library-bank-question-card {
    border-radius: 16px;
  }

  .library-bank-view-modal > .modal-actions {
    padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid var(--border-soft);
    background: var(--surface);
  }
}
</style>
