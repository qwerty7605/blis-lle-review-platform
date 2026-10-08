<template>
  <ol class="steps">
    <li v-for="(action, index) in actions" :key="`${action.key}-${action.area_key}`" class="step">
      <span class="step-number" aria-hidden="true">{{ index + 1 }}</span>

      <div class="step-body">
        <div class="step-head">
          <span class="step-tag" :class="`is-${tagFor(action).tone}`">
            <component :is="tagFor(action).icon" :size="12" />
            {{ tagFor(action).label }}
          </span>
        </div>

        <h3 class="step-title">{{ action.title || action.advice?.lead }}</h3>
        <p v-if="action.subject" class="step-subject">{{ action.subject }}</p>

        <p v-if="action.summary" class="step-summary">{{ action.summary }}</p>

        <!-- What to do about it, when the planner spells that out. -->
        <p v-if="action.next" class="step-next">
          <ArrowRight :size="14" />
          <span>{{ action.next }}</span>
        </p>

        <!-- How well the topic is known so far, as one labelled bar. -->
        <div v-if="action.mastery" class="step-meter">
          <span class="step-meter-label">How well you know it</span>
          <span
            class="step-meter-track"
            role="img"
            :aria-label="`About ${masteryPercent(action)} percent`"
          >
            <span
              class="step-meter-fill"
              :class="`is-${action.mastery.band}`"
              :style="{ width: `${Math.max(masteryPercent(action), 3)}%` }"
            />
          </span>
          <span class="step-meter-value">{{ masteryPercent(action) }}%</span>
        </div>

        <!-- Only where there is somewhere to go: a time estimate on its own,
             with no button beside it, reads as a stray caption. -->
        <div v-if="action.cta_label && action.cta_route" class="step-foot">
          <RouterLink
            class="step-cta"
            :to="{ name: action.cta_route, query: action.cta_query ?? undefined }"
          >
            {{ ctaLabel || action.cta_label }}
            <ArrowRight :size="15" />
          </RouterLink>
          <span v-if="action.effort" class="step-effort">{{ effortText(action) }}</span>
        </div>
      </div>
    </li>
  </ol>
</template>

<script setup>
import {
  ArrowRight,
  CircleDashed,
  FileQuestion,
  Hourglass,
  KeyRound,
  RotateCcw,
  Tags,
  Target,
  TrendingDown,
  TrendingUp,
  UserRound,
} from 'lucide-vue-next'

/*
 * A numbered list of suggested next steps: what it is about, one plain
 * sentence on why it is listed, what to do, and a button when there is
 * somewhere to go.
 *
 * Shared by the student Analytics page ("What To Review Next", fed by
 * StudyActionPlanner) and the teacher dashboard ("What To Do Next", fed by
 * TeacherActionPlanner). Both planners emit the same row shape; this shows
 * their one-line `summary` and `next` rather than the long `advice` breakdown.
 */
defineProps({
  actions: {
    type: Array,
    default: () => [],
  },
  // One fixed button label for every step (the student list says "Practice
  // now"). Left empty, each step uses the label its planner gave it.
  ctaLabel: {
    type: String,
    default: '',
  },
})

// One short label per kind of suggestion, keyed on the planner's stable key.
const TAGS = {
  reverse_slide: { label: 'Slipping', icon: TrendingDown, tone: 'alert' },
  drill_weak_area: { label: 'Weakest topic', icon: Target, tone: 'alert' },
  close_the_gap: { label: 'Almost passing', icon: TrendingUp, tone: 'good' },
  fill_blind_spot: { label: 'Not started yet', icon: CircleDashed, tone: 'neutral' },
  practice_not_published: { label: 'No practice yet', icon: Hourglass, tone: 'neutral' },
  // Teacher dashboard
  publish_practice: { label: 'No practice set', icon: FileQuestion, tone: 'alert' },
  check_answer_key: { label: 'Check answer key', icon: KeyRound, tone: 'alert' },
  reteach_topic: { label: 'Reteach', icon: RotateCcw, tone: 'alert' },
  follow_up_students: { label: 'Follow up', icon: UserRound, tone: 'neutral' },
  confirm_tags: { label: 'Check topics', icon: Tags, tone: 'neutral' },
}

function tagFor(action) {
  return TAGS[action.key] ?? { label: 'Suggested', icon: Target, tone: 'neutral' }
}

function masteryPercent(action) {
  return Math.round(Number(action.mastery?.mastery_percent ?? 0))
}

// "Start 4-item drill" + "about 3 min" -> "4 questions · about 3 min".
function effortText(action) {
  const size = String(action.cta_label ?? '').match(/(\d+)-item/)?.[1]
  return size ? `${size} questions · ${action.effort}` : action.effort
}
</script>

<style scoped>
.steps {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 12px;
}

.step {
  display: flex;
  gap: 14px;
  padding: 16px;
  border-radius: 16px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
}

.step-number {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--lnu-navy);
  color: var(--lnu-gold-light);
  font-size: 14px;
  font-weight: 600;
}

.step-body {
  flex: 1;
  min-width: 0;
  display: grid;
  gap: 4px;
}

.step-head {
  display: flex;
}

.step-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
}

.step-tag.is-alert { background: rgba(201, 168, 76, 0.24); color: #6b5310; }
.step-tag.is-good { background: rgba(46, 125, 50, 0.12); color: var(--lnu-success); }
.step-tag.is-neutral { background: rgba(26, 35, 126, 0.08); color: var(--lnu-navy-light); }

.step-title {
  margin: 6px 0 0;
  font-size: 16px;
  font-weight: 600;
  line-height: 1.35;
  color: #0b0d17;
  overflow-wrap: anywhere;
}

.step-subject {
  margin: 0;
  font-size: 12.5px;
  color: var(--lnu-text-muted);
}

.step-summary {
  margin: 6px 0 0;
  font-size: 13.5px;
  line-height: 1.55;
  color: var(--lnu-text);
}

.step-next {
  margin: 6px 0 0;
  display: flex;
  align-items: flex-start;
  gap: 7px;
  font-size: 13.5px;
  font-weight: 500;
  line-height: 1.55;
  color: var(--lnu-navy);
}

.step-next svg {
  flex-shrink: 0;
  margin-top: 4px;
}

.step-meter {
  margin-top: 10px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 6px 10px;
}

.step-meter-label {
  grid-column: 1 / -1;
  font-size: 12px;
  font-weight: 500;
  color: var(--lnu-text-muted);
}

.step-meter-track {
  height: 8px;
  border-radius: 999px;
  background: rgba(13, 21, 71, 0.09);
  overflow: hidden;
}

.step-meter-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: var(--lnu-navy-light);
}

/* Amber for a low score, matching the "At Risk" gauge; green once it is solid. */
.step-meter-fill.is-struggling { background: #b7791f; }
.step-meter-fill.is-mastered { background: var(--lnu-success); }

.step-meter-value {
  font-size: 13.5px;
  font-weight: 600;
  color: #0b0d17;
}

.step-foot {
  margin-top: 12px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 12px;
}

.step-cta {
  height: 40px;
  padding: 0 16px;
  border-radius: 10px;
  background: var(--lnu-navy);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 13.5px;
  font-weight: 600;
  text-decoration: none;
  transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
}

.step-cta:hover {
  background: var(--lnu-navy-light);
  box-shadow: 0 10px 18px rgba(26, 35, 126, 0.25);
  transform: translateY(-1px);
}

.step-cta:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

.step-effort {
  font-size: 12.5px;
  color: var(--lnu-text-muted);
}

@media (pointer: coarse) {
  .step-cta {
    height: 44px;
  }
}

@media (max-width: 480px) {
  .step {
    gap: 12px;
    padding: 14px;
  }

  .step-number {
    width: 28px;
    height: 28px;
    font-size: 13px;
  }

  .step-cta {
    width: 100%;
    justify-content: center;
  }
}
</style>
