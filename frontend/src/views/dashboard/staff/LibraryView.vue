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
        <h2 class="library-hero-title">
          Assessment Library
          <InfoTip on-dark text="Your question banks. Upload a DOCX and its questions are saved here, ready to build exams from." />
        </h2>

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
      <strong>No question banks yet <InfoTip text="Upload a DOCX of numbered questions. They are converted and tagged by topic for you." /></strong>
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
              <h4>Add Assessment Questions <InfoTip text="Pick a DOCX. The name and item mapping are handled on save; the core subject is detected and yours to change." /></h4>
            </div>
            <button type="button" class="modal-close" @click="closeLibraryQuestionModal">
              <X :size="16" />
            </button>
          </header>

          <div class="library-modal-body">
            <section class="library-form-panel">
              <label class="field-stack">
                <span class="field-label">
                  Upload a DOCX file (Questions)
                  <InfoTip text="Use numbered questions with optional choices like A., B., C." />
                </span>
                <div class="library-upload-panel">
                  <input
                    :key="libraryFileInputKey"
                    type="file"
                    accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    class="file-input"
                    :disabled="libraryParsing"
                    @change="handleLibraryDocxChange"
                  />
                </div>
              </label>

              <p v-if="libraryDocxName" class="library-file-chip">{{ libraryDocxName }}</p>

              <label class="field-stack">
                <span class="field-label">Core Subject</span>
                <select
                  v-model="libraryForm.subjectCategory"
                  class="text-input"
                  :disabled="librarySaving"
                  @change="markLibrarySubjectChosen"
                >
                  <option value="">General subject</option>
                  <option v-for="subject in librarySubjectCategories" :key="subject" :value="subject">
                    {{ subject }}
                  </option>
                </select>
                <p v-if="librarySubjectNote" class="library-upload-note">{{ librarySubjectNote }}</p>
              </label>

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
                        maxlength="255"
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
              <span v-if="pendingTagCount > 0" class="pill neutral">
                {{ pendingTagCount }} {{ pendingTagCount === 1 ? 'tag' : 'tags' }} to confirm
              </span>
              <span v-else-if="allTagsConfirmed" class="pill success tag-confirmed-pill">
                <CheckCircle2 :size="12" />
                All tags confirmed
              </span>
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
                    <span v-if="question.topic?.name" class="pill navy">{{ question.topic.name }}</span>
                    <span v-if="question.subtopic?.name" class="pill neutral">{{ question.subtopic.name }}</span>
                    <span v-if="question.difficulty" class="pill pill-cap success">{{ question.difficulty }}</span>
                    <span v-if="question.cognitive_level" class="pill neutral">{{ cognitiveLevelLabel(question.cognitive_level) }}</span>
                    <span v-if="question.tag_status === 'pending_review'" class="pill neutral">Tag pending review</span>
                    <span v-else-if="question.tag_status === 'confirmed'" class="pill success tag-confirmed-pill">
                      <CheckCircle2 :size="12" />
                      Confirmed
                    </span>
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
                    <textarea v-model.trim="questionEditForm.question_text" class="text-input" rows="3" v-bind="questionEditChecks.bind('question_text')"></textarea>
                    <FieldError :checks="questionEditChecks" field="question_text" />
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
                      <p v-if="!selectedLibraryQuestionBank?.subject" class="library-upload-note">
                        Topics belong to a core subject. Give this set one first, under Edit.
                      </p>
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
                      <input
                        v-model.trim="option.text"
                        type="text"
                        class="text-input"
                        :class="{ 'is-invalid': questionEditChecks.error('options') && !option.text?.trim() }"
                        :data-check="option.text?.trim() ? undefined : 'question-edit.options'"
                        :aria-label="`Choice ${option.label}`"
                      />
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
                    <FieldError :checks="questionEditChecks" field="options" />
                  </div>

                  <div v-if="libraryError" class="feedback danger" role="alert">
                    <AlertCircle :size="15" />
                    <span>{{ libraryError }}</span>
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
              v-if="pendingTagCount > 0"
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
              <input v-model.trim="libraryBankEditForm.title" type="text" class="text-input" maxlength="255" v-bind="bankEditChecks.bind('title')" />
              <FieldError :checks="bankEditChecks" field="title" />
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

          <div class="library-edit-questions">
            <div class="library-edit-questions-text">
              <span class="field-label">
                Questions and Mapping
                <InfoTip text="Open the questions to edit each one's wording, answer, topic, subtopic, difficulty and cognitive level." />
              </span>
            </div>
            <button
              type="button"
              class="ghost-btn"
              :disabled="libraryBankEditSaving"
              @click="editLibraryBankQuestions"
            >
              <Pencil :size="14" />
              {{ libraryBankEditDirty ? 'Save and Edit Questions' : 'Edit Questions' }}
            </button>
          </div>

          <!-- The page's banner is behind this dialog. -->
          <div v-if="libraryError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ libraryError }}</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="libraryBankEditSaving" @click="closeEditLibraryBankModal">
              Cancel
            </button>
            <button
              type="button"
              class="primary-btn"
              :disabled="libraryBankEditSaving"
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
import FieldError from '@/components/FieldError.vue'
import InfoTip from '@/components/InfoTip.vue'
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
  librarySubjectNote,
  markLibrarySubjectChosen,
  libraryBankEditForm,
  bankEditChecks,
  questionEditChecks,
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
  libraryBankEditDirty,
  editLibraryBankQuestions,
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

// The open set's tags: how many still wait for a teacher, and whether every
// tagged question is confirmed (what "Confirm All Suggested Tags" leaves).
const pendingTagCount = computed(() => (
  libraryBankQuestions.value.filter((question) => question.tag_status === 'pending_review').length
))
const allTagsConfirmed = computed(() => (
  libraryBankQuestions.value.length > 0
  && libraryBankQuestions.value.every((question) => question.tag_status === 'confirmed')
))

/*
 * "Item mapping" in plain words: whether each question has been tagged with
 * its topic, difficulty and thinking level yet, and what the button will do.
 */
function mappingState(bank) {
  const status = String(bank.mapping_status ?? 'idle').toLowerCase()

  if (status === 'completed') {
    // Tagged is not yet confirmed: the tags the tagger was unsure of wait
    // for a teacher, in the set's question list.
    const pending = Number(bank.pending_tags_count ?? 0)

    if (pending > 0) {
      return {
        tone: 'review',
        icon: AlertCircle,
        label: `${pending} ${pending === 1 ? 'tag' : 'tags'} to confirm`,
        detail: 'Tagged, but these were uncertain. Open the set to check and confirm them.',
        action: 'Tag again',
      }
    }

    return {
      tone: 'done',
      icon: CheckCircle2,
      label: 'Tags confirmed',
      detail: 'Every question has a confirmed topic, difficulty and thinking level.',
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

<style scoped src="./libraryView.css"></style>
