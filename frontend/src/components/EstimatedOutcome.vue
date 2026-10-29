<template>
  <div class="outcome">
    <!-- Coloured by the same verdict as the label under it, so the gauge and
         the words can never disagree. -->
    <ConfidenceGauge :value="readiness.percent" :tone="readiness.tone" label="Chance of passing" />

    <div class="outcome-summary">
      <p class="outcome-label" :class="`verdict-${readiness.tone}`">
        <Lock v-if="!readiness.ready" :size="17" aria-hidden="true" />
        {{ readiness.label }}
      </p>
      <p v-if="readiness.headline" class="outcome-headline">{{ readiness.headline }}</p>
      <p v-if="readiness.seriesText" class="outcome-progress">{{ readiness.seriesText }}</p>

      <div v-if="readiness.scores.length" class="outcome-list">
        <p class="outcome-list-title"><Target :size="14" aria-hidden="true" />Subject scores in that series</p>
        <ul class="outcome-scores">
          <li v-for="row in readiness.scores" :key="row.key">
            <span>{{ row.subject }}</span>
            <strong>{{ row.score }}</strong>
          </li>
        </ul>
      </div>

      <p class="outcome-detail">{{ readiness.detail }}</p>

      <ul v-if="readiness.inProgress.length" class="outcome-in-progress">
        <li v-for="series in readiness.inProgress" :key="series.key">{{ series.text }}</li>
      </ul>

      <div class="outcome-stat-row">
        <div class="outcome-stat">
          <span class="outcome-stat-label">Average Score</span>
          <strong class="outcome-stat-value">{{ averageScoreDisplay }}</strong>
        </div>
        <!-- Subjects whose latest score is under the passing mark. -->
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
import { Lock, Target } from 'lucide-vue-next'
import ConfidenceGauge from '@/components/charts/ConfidenceGauge.vue'

/*
 * The body of the "Estimated Outcome" card: the estimated chance of passing,
 * At risk or Not at risk, the pre-test or mock board it is read from with
 * its six subject scores, the note on what it is, and any series still under
 * way. Shared by the student dashboard, the Analytics page and the staff's
 * student profile, so the same result looks the same in all three. The card
 * around it (title, surface) belongs to each page.
 */
defineProps({
  // From describeReadiness() in views/dashboard/composables/readiness.js.
  readiness: { type: Object, required: true },
  averageScoreDisplay: { type: String, default: 'N/A' },
  focusSubjectsCount: { type: Number, default: 0 },
})
</script>

<style scoped>
/* Gauge on top, verdict under it, then the figures across the full width. */
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
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 20px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--dk-fg-0b0d17, #0b0d17);
}

/* Amber, not red, for "At risk": it reads as "pay attention" without the
   alarm. */
.outcome-label.verdict-success { color: var(--dk-fg-lnu-success, var(--lnu-success)); }
.outcome-label.verdict-danger { color: var(--dk-fg-9a6a0c, #9a6a0c); }
.outcome-label.verdict-neutral { color: var(--dk-fg-lnu-navy, var(--lnu-navy)); }

.outcome-headline {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
}

.outcome-detail {
  margin: 0 auto;
  max-width: 46ch;
  font-size: 13px;
  line-height: 1.55;
  color: var(--lnu-text-muted);
}

.outcome-progress {
  margin: 0 auto;
  max-width: 46ch;
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--lnu-text);
}

.outcome-in-progress {
  margin: 0 auto;
  padding: 0;
  list-style: none;
  max-width: 46ch;
  display: grid;
  gap: 4px;
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

.outcome-list-title {
  margin: 0 0 4px;
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
}

.outcome-scores {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 2px;
  font-size: 13px;
  line-height: 1.5;
  color: var(--lnu-text);
}

.outcome-scores li {
  display: flex;
  justify-content: space-between;
  gap: 10px;
}

.outcome-scores li span {
  min-width: 0;
  overflow-wrap: anywhere;
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
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
}

@media (max-width: 640px) {
  .outcome-label {
    font-size: 18px;
  }
}
</style>
