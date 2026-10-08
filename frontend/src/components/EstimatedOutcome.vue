<template>
  <div class="outcome">
    <!-- Coloured by the same verdict as the label under it, so the gauge and
         the words can never disagree. -->
    <ConfidenceGauge :value="readiness.confidence" :tone="readiness.tone" label="Confidence" />

    <div class="outcome-summary">
      <p class="outcome-label" :class="`verdict-${readiness.tone}`">{{ readiness.label }}</p>
      <p class="outcome-detail">{{ readiness.detail }}</p>

      <div class="outcome-stat-row">
        <div class="outcome-stat">
          <span class="outcome-stat-label">Average Score</span>
          <strong class="outcome-stat-value">{{ averageScoreDisplay }}</strong>
        </div>
        <div class="outcome-stat">
          <span class="outcome-stat-label">Focus Areas</span>
          <strong class="outcome-stat-value">
            {{ focusSubjectsCount }} {{ focusSubjectsCount === 1 ? 'Subject' : 'Subjects' }}
          </strong>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import ConfidenceGauge from '@/components/charts/ConfidenceGauge.vue'

/*
 * The body of the "Estimated Outcome" card: gauge, verdict, and the two
 * figures behind it. Shared by the student dashboard and the Analytics page so
 * the same estimate looks the same in both places. The card around it (title,
 * surface) belongs to each page.
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

.outcome-summary {
  width: 100%;
  min-width: 0;
  display: grid;
  gap: 6px;
  text-align: center;
}

.outcome-label {
  margin: 0;
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
