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

    <article class="surface-card">
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
          <label class="field-stack">
            <span class="field-label">Platform Name</span>
            <input v-model.trim="settingsForm.platform_name" type="text" class="text-input" :disabled="!settingsCanEdit" />
          </label>

          <label class="field-stack">
            <span class="field-label">Academic Term</span>
            <input v-model.trim="settingsForm.academic_term" type="text" class="text-input" :disabled="!settingsCanEdit" />
          </label>

          <label class="check-item">
            <input v-model="settingsForm.allow_public_registration" type="checkbox" :disabled="!settingsCanEdit" />
            <span>Allow public student registration</span>
          </label>

          <label class="check-item">
            <input v-model="settingsForm.maintenance_mode" type="checkbox" :disabled="!settingsCanEdit" />
            <span>Enable maintenance mode</span>
          </label>

          <template v-if="settingsCanEdit">
            <label class="field-stack">
              <span class="field-label">Announcement Banner</span>
              <textarea
                v-model.trim="settingsForm.announcement_banner"
                rows="3"
                class="text-input textarea-input"
              />
            </label>

            <label class="field-stack">
              <span class="field-label">Terms of Use</span>
              <textarea
                v-model.trim="settingsForm.terms_of_use_text"
                rows="10"
                class="text-input textarea-input settings-long-text"
              />
            </label>

            <label class="field-stack">
              <span class="field-label">Privacy Policy</span>
              <textarea
                v-model.trim="settingsForm.privacy_policy_text"
                rows="10"
                class="text-input textarea-input settings-long-text"
              />
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
import { useSettingsModule } from '../composables/useSettingsModule'
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
  loadSystemSettings,
  saveSystemSettings,
} = useSettingsModule({ enabled: () => isAdmin.value })

const readOnlyDocuments = computed(() => [
  { label: 'Announcement Banner', text: settingsForm.announcement_banner },
  { label: 'Terms of Use', text: settingsForm.terms_of_use_text },
  { label: 'Privacy Policy', text: settingsForm.privacy_policy_text },
])
</script>

<style scoped src="../dashboard.css"></style>

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
</style>
