<template>
  <div class="outcome">
    <!-- Which of the two readings this is: an early warning (years 1 to 3)
         or a board readiness prediction (year 4). -->
    <span v-if="readiness.modeLabel" class="outcome-mode">{{ readiness.modeLabel }}</span>

    <!-- Coloured by the same verdict as the label under it, so the gauge and
         the words can never disagree. -->
    <ConfidenceGauge :value="readiness.confidence" :tone="readiness.tone" label="Confidence" />

    <div class="outcome-summary">
      <p class="outcome-label" :class="`verdict-${readiness.tone}`">
        <Lock v-if="!readiness.ready" :size="17" aria-hidden="true" />
        {{ readiness.label }}
      </p>
      <p class="outcome-detail">{{ readiness.detail }}</p>

      <p v-if="readiness.progress" class="outcome-progress">
        {{ readiness.progress.text }}<template v-if="readiness.progress.missing.length">.
          Still to take: {{ readiness.progress.missing.join(', ') }}</template>.
      </p>

      <p v-if="readiness.semesterLabel" class="outcome-progress">From the {{ readiness.semesterLabel }}</p>

      <div v-if="readiness.riskFlags.length" class="outcome-list is-risk">
        <p class="outcome-list-title"><AlertTriangle :size="14" aria-hidden="true" />Risk indicators</p>
        <ul>
          <li v-for="flag in readiness.riskFlags" :key="flag.key">{{ flag.text }}</li>
        </ul>
      </div>

      <div v-if="readiness.focusAreas.length" class="outcome-list">
        <p class="outcome-list-title"><Target :size="14" aria-hidden="true" />Focus areas</p>
        <ul>
          <li v-for="area in readiness.focusAreas" :key="area.key">
            {{ area.subject }} <strong>{{ area.score }}</strong>
          </li>
        </ul>
      </div>

      <div class="outcome-stat-row">
        <div class="outcome-stat">
          <span class="outcome-stat-label">Average Score</span>
          <strong class="outcome-stat-value">{{ averageScoreDisplay }}</strong>
        </div>
        <!-- Subjects whose latest score is under the passing mark. Not the
             two "Focus areas" above, which are this semester's two lowest. -->
        <div class="outcome-stat">
          <span class="outcome-stat-label">Below Passing Mark</span>
          <strong class="outcome-stat-value">
            {{ focusSubjectsCount }} {{ focusSubjectsCount === 1 ? 'Subject' : 'Subjects' }}
          </strong>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { AlertTriangle, Lock, Target } from 'lucide-vue-next'
import ConfidenceGauge from '@/components/charts/ConfidenceGauge.vue'

/*
 * The body of the "Estimated Outcome" card: gauge, verdict, the note that
 * says what kind of reading it is, the subjects flagged, and the two figures
 * behind it. Shared by the student dashboard, the Analytics page and the
 * staff's student profile, so the same result looks the same in all three.
 * The card around it (title, surface) belongs to each page.
 */
defineProps({
  // From describeReadiness() in views/dashboard/composables/readiness.js.
  readiness: { type: Object, required: true },
  averageScoreDisplay: { type: String, default: 'N/A' },
  focusSubjectsCount: { type: Number, default: 0 },
})
</script>

<style scoped>
/* Gauge on top, verdict under it, then the two figures across the full
   width: side by side, the gauge left the stat tiles too narrow to read. */
.outcome {
  display: grid;
  justify-items: center;
  gap: 14px;
}

.outcome-mode {
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(26, 35, 126, 0.08);
  color: var(--lnu-navy);
  font-size: 12px;
  font-weight: 600;
}

.outcome-summary {
  width: 100%;
  min-width: 0;
  display: grid;
  gap: 6px;
  text-align: center;
}

.outcome-label {
  margin: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 20px;
  font-weight: 600;
  line-height: 1.2;
  color: #0b0d17;
}

/* Amber, not red, for the at-risk verdict: it reads as "pay attention"
   without the alarm. */
.outcome-label.verdict-success { color: var(--lnu-success); }
.outcome-label.verdict-danger { color: #9a6a0c; }
.outcome-label.verdict-neutral { color: var(--lnu-navy); }

.outcome-detail {
  margin: 0 auto;
  max-width: 42ch;
  font-size: 13px;
  line-height: 1.55;
  color: var(--lnu-text-muted);
}

.outcome-progress {
  margin: 0 auto;
  max-width: 42ch;
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--lnu-text);
}

.outcome-list {
  margin-top: 6px;
  padding: 10px 12px;
  border-radius: 14px;
  background: var(--surface-muted);
  border: 1px solid var(--border-soft);
  text-align: left;
}

.outcome-list.is-risk {
  background: rgba(201, 138, 30, 0.1);
  border-color: rgba(201, 138, 30, 0.35);
}

.outcome-list-title {
  margin: 0 0 4px;
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: var(--lnu-navy);
}

.outcome-list.is-risk .outcome-list-title { color: #7a5309; }

.outcome-list ul {
  margin: 0;
  padding-left: 18px;
  display: grid;
  gap: 2px;
  font-size: 13px;
  line-height: 1.5;
  color: var(--lnu-text);
}

.outcome-stat-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin-top: 10px;
  text-align: left;
}

.outcome-stat {
  display: grid;
  gap: 2px;
  padding: 10px 12px;
  border-radius: 14px;
  background: var(--surface-muted);
  border: 1px solid var(--border-soft);
}

.outcome-stat-label {
  font-size: 11.5px;
  font-weight: 500;
  color: var(--lnu-text-muted);
}

.outcome-stat-value {
  font-size: 16px;
  font-weight: 600;
  color: var(--lnu-navy);
}

@media (max-width: 640px) {
  .outcome-label {
    font-size: 18px;
  }
}
</style>
