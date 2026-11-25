<template>
  <section class="room-view">
    <!-- Everyone's own account first; the platform's settings follow for the
         administrator, who is the only one able to change them. -->
    <AccountSettings />
    <ExamDefaultsCard v-if="isTeacher" />
    <AccountSessions />
    <FeedbackCard />

    <template v-if="isAdmin">
    <div v-if="settingsMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ settingsMessage }}</span>
    </div>
    <div v-if="settingsError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ settingsError }}</span>
    </div>

    <article class="surface-card" data-guide="system-settings">
      <header class="surface-head">
        <h3>System Settings</h3>
        <span class="pill" :class="settingsCanEdit ? 'success' : 'neutral'">
          {{ settingsCanEdit ? 'Admin editable' : 'Read only' }}
        </span>
      </header>

      <div v-if="settingsLoading" class="room-detail-loading">
        <RefreshCw :size="15" class="spin-soft" />
        <span>Loading settings...</span>
      </div>

      <template v-else>
        <div class="settings-grid">
          <fieldset class="settings-calendar">
            <legend class="field-label">
              School Calendar
              <InfoTip
                label="How the school calendar works"
                text="Each semester runs until the other one starts, so with no midyear term June and July belong to the 2nd semester. A school year is named by the year its 1st semester starts in: January 2027 is the 2nd semester of AY 2026-2027."
              />
            </legend>

            <div class="settings-calendar-months">
              <label class="field-stack">
                <span class="settings-calendar-label">1st Semester Starts</span>
                <select
                  v-model.number="settingsForm.first_semester_start_month"
                  class="text-input"
                  :disabled="!settingsCanEdit"
                  v-bind="settingsChecks.bind('first_semester_start_month')"
                >
                  <option v-for="month in MONTH_OPTIONS" :key="month.value" :value="month.value">{{ month.label }}</option>
                </select>
                <FieldError :checks="settingsChecks" field="first_semester_start_month" />
              </label>

              <label class="field-stack">
                <span class="settings-calendar-label">2nd Semester Starts</span>
                <select
                  v-model.number="settingsForm.second_semester_start_month"
                  class="text-input"
                  :disabled="!settingsCanEdit"
                  v-bind="settingsChecks.bind('second_semester_start_month')"
                >
                  <option v-for="month in MONTH_OPTIONS" :key="month.value" :value="month.value">{{ month.label }}</option>
                </select>
                <FieldError :checks="settingsChecks" field="second_semester_start_month" />
              </label>
            </div>

            <p v-if="currentTerm" class="settings-calendar-now">Today is in the <strong>{{ currentTerm }}</strong>.</p>
            <p class="settings-calendar-note">
              Exams created from now on are filed under the term of their start date by these months, and a student who signs up gets the admission year of the current school year. Exams already saved keep their term.
            </p>
          </fieldset>

          <fieldset class="settings-calendar">
            <legend class="field-label">
              Students' Passing Mark
              <InfoTip
                label="How the students' passing mark works"
                text="Students keep their real score and need this mark to pass an exam. Teachers and administrators see each score as a grade on the usual scale, where this mark is 75 (the LLE's passing average), so both see the same students passing. At 75 nothing is converted. The Estimated Outcome and the LLE results do not change."
              />
            </legend>

            <label class="field-stack settings-passing-field">
              <span class="settings-calendar-label">Passing Mark (%)</span>
              <input
                v-model.number="settingsForm.students_passing_mark"
                type="number"
                inputmode="numeric"
                min="75"
                max="95"
                step="1"
                class="text-input"
                :disabled="!settingsCanEdit"
                v-bind="settingsChecks.bind('students_passing_mark')"
              />
              <FieldError :checks="settingsChecks" field="students_passing_mark" />
            </label>

            <p v-if="passingMarkPreview" class="settings-calendar-now">{{ passingMarkPreview }}</p>
            <p v-if="settingsCanEdit && passingMarkChanged" class="feedback info" role="note">
              Saving re-judges every exam already taken, on every page, at once: a score that passed before may not pass now.
            </p>
          </fieldset>

          <label class="check-item">
            <input v-model="settingsForm.allow_public_registration" type="checkbox" :disabled="!settingsCanEdit" />
            <span>Allow public student registration</span>
          </label>

          <template v-if="settingsCanEdit">
            <label class="field-stack">
              <span class="field-label">Announcement Banner</span>
              <textarea
                v-model.trim="settingsForm.announcement_banner"
                rows="3"
                class="text-input textarea-input"
                v-bind="settingsChecks.bind('announcement_banner')"
              />
              <FieldError :checks="settingsChecks" field="announcement_banner" />
            </label>

            <label class="field-stack">
              <span class="field-label">Terms of Use</span>
              <textarea
                v-model.trim="settingsForm.terms_of_use_text"
                rows="10"
                class="text-input textarea-input settings-long-text"
                v-bind="settingsChecks.bind('terms_of_use_text')"
              />
              <FieldError :checks="settingsChecks" field="terms_of_use_text" />
            </label>

            <label class="field-stack">
              <span class="field-label">Privacy Policy</span>
              <textarea
                v-model.trim="settingsForm.privacy_policy_text"
                rows="10"
                class="text-input textarea-input settings-long-text"
                v-bind="settingsChecks.bind('privacy_policy_text')"
              />
              <FieldError :checks="settingsChecks" field="privacy_policy_text" />
            </label>
          </template>

          <!-- Someone who can only read these gets them as text on the page.
               In a disabled box ten lines tall the rest of each document was
               out of reach on a phone, where a disabled field does not scroll. -->
          <template v-else>
            <div v-for="document in readOnlyDocuments" :key="document.label" class="field-stack">
              <span class="field-label">{{ document.label }}</span>
              <p class="settings-read-text" :class="{ 'is-empty': !document.text }">{{ document.text || 'Not set.' }}</p>
            </div>
          </template>
        </div>

        <div class="modal-actions">
          <button type="button" class="ghost-btn" :disabled="settingsLoading" @click="loadSystemSettings">Reload</button>
          <!-- Only for someone who can save. Greyed out for everyone else it
               read as a button that was broken. -->
          <button
            v-if="settingsCanEdit"
            type="button"
            class="primary-btn"
            :disabled="settingsSaving"
            @click="saveSystemSettings"
          >
            <RefreshCw v-if="settingsSaving" :size="14" class="spin-soft" />
            <span>{{ settingsSaving ? 'Saving...' : 'Save Settings' }}</span>
          </button>
        </div>
      </template>
    </article>
    </template>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { AlertCircle, CheckCircle2, RefreshCw } from 'lucide-vue-next'
import { useAuthStore } from '@/store/auth.store'
import { MONTH_OPTIONS, useSettingsModule } from '../composables/useSettingsModule'
import FieldError from '@/components/FieldError.vue'
import InfoTip from '@/components/InfoTip.vue'
import AccountSessions from './AccountSessions.vue'
import AccountSettings from './AccountSettings.vue'
import ExamDefaultsCard from './ExamDefaultsCard.vue'
import FeedbackCard from './FeedbackCard.vue'

const role = computed(() => String(useAuthStore().user?.role ?? '').toLowerCase())
const isAdmin = computed(() => role.value === 'admin')
// Administrators make no exams.
const isTeacher = computed(() => ['staff_master_examiner', 'faculty'].includes(role.value))

const {
  settingsLoading,
  settingsSaving,
  settingsError,
  settingsMessage,
  settingsCanEdit,
  settingsForm,
  currentTerm,
  passingMarkPreview,
  passingMarkChanged,
  settingsChecks,
  loadSystemSettings,
  saveSystemSettings,
} = useSettingsModule({ enabled: () => isAdmin.value })

const readOnlyDocuments = computed(() => [
  { label: 'Announcement Banner', text: settingsForm.announcement_banner },
  { label: 'Terms of Use', text: settingsForm.terms_of_use_text },
  { label: 'Privacy Policy', text: settingsForm.privacy_policy_text },
])
</script>

<style scoped>
/* The two documents are pages long. Where the browser can size a field to
   its content the box grows with the text and the page scrolls; elsewhere
   it stays the ten lines its rows attribute gives it. */
.settings-long-text {
  field-sizing: content;
  min-height: 12em;
}

.settings-read-text {
  margin: 0;
  padding: 12px 14px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  background: var(--surface-muted);
  color: var(--ink);
  font-size: 13.5px;
  line-height: 1.6;
  white-space: pre-line;
  overflow-wrap: anywhere;
}

.settings-read-text.is-empty {
  color: var(--ink-muted);
}

.settings-calendar {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 8px;
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}

.settings-calendar legend {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 2px;
  padding: 0;
}

.settings-calendar-months {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  align-items: start; /* a message under one picker must not push the other down */
  gap: 12px;
}

.settings-calendar-label {
  color: var(--ink-soft);
  font-size: 12.5px;
  font-weight: 600;
}

.settings-calendar-now,
.settings-calendar-note {
  margin: 0;
  font-size: 13px;
  line-height: 1.5;
}

.settings-calendar-now {
  color: var(--ink);
}

.settings-calendar-note {
  color: var(--ink-muted);
}

/* A three-digit number: as wide as one month picker, not the whole card. */
.settings-passing-field {
  max-width: 220px;
}

@media (max-width: 480px) {
  .settings-calendar-months {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
