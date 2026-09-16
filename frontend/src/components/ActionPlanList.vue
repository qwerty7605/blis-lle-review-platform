<template>
<ol class="action-list">
  <li
    v-for="(action, index) in actions"
    :key="`${action.key}-${action.area_key}`"
    class="action-card"
    :class="[`is-${action.tone}`, index === 0 ? 'is-lead' : 'is-compact']"
  >
    <div class="action-head">
      <span class="action-chip" aria-hidden="true">
        <component :is="actionStyle(action.tone).icon" :size="index === 0 ? 15 : 13" />
      </span>
      <span class="action-kicker">{{ kickerFor(index, action) }}</span>
      <span v-if="action.effort" class="action-effort">
        <Clock :size="11" />
        {{ action.effort }}
      </span>
    </div>

    <p class="action-lead">{{ action.advice.lead }}</p>

    <!--
      The tracing estimate as a bar rather than another sentence.
      The prose states the number where it carries meaning; this is
      the at-a-glance version, and it replaces the footer line that
      used to repeat both the figure and the item count in words.
    -->
    <div v-if="action.mastery" class="action-meter">
      <span
        class="action-meter-track"
        role="img"
        :aria-label="`Estimated grasp ${Math.round(action.mastery.mastery_percent)} percent, from ${action.mastery.opportunities} answered items`"
      >
        <span
          class="action-meter-fill"
          :class="`is-${action.mastery.band}`"
          :style="{ width: `${Math.max(Math.round(action.mastery.mastery_percent), 3)}%` }"
        />
      </span>
      <span class="action-meter-value" :class="`is-${action.mastery.band}`">
        {{ Math.round(action.mastery.mastery_percent) }}%
      </span>
      <span class="action-meter-note">grasp · {{ action.mastery.opportunities }} {{ action.mastery.opportunities === 1 ? 'item' : 'items' }}</span>
    </div>

    <!--
      Only the lead action is expanded on arrival. Every card
      carrying its full reasoning made the panel taller than the
      rest of the page put together, and the reasoning is what you
      read when you want to question the suggestion -- not before.
    -->
    <div v-show="isActionOpen(index)" class="action-breakdown">
      <div v-for="block in action.advice.blocks" :key="block.label" class="action-block">
        <span class="action-block-label">{{ block.label }}</span>
        <ul v-if="block.points.length > 1" class="action-points">
          <li v-for="(point, pointIndex) in block.points" :key="pointIndex">{{ point }}</li>
        </ul>
        <p v-else class="action-block-text">{{ block.points[0] }}</p>
      </div>
    </div>

    <div class="action-foot">
      <RouterLink
        v-if="action.cta_label && action.cta_route"
        class="action-cta"
        :class="{ 'is-lead': index === 0 }"
        :to="{ name: action.cta_route, query: action.cta_query ?? undefined }"
      >
        {{ action.cta_label }}
        <ArrowRight :size="14" />
      </RouterLink>

      <button
        v-if="action.advice.blocks.length > 0"
        type="button"
        class="action-toggle"
        :aria-expanded="isActionOpen(index)"
        @click="toggleAction(index)"
      >
        {{ isActionOpen(index) ? 'Hide details' : 'Why this?' }}
        <ChevronDown :size="13" class="action-toggle-icon" :class="{ 'is-open': isActionOpen(index) }" />
      </button>
    </div>
  </li>
</ol>
</template>

<script setup>
import { ref } from 'vue'
import {
  ArrowRight,
  ChevronDown,
  Clock,
  Info,
  Minus,
  Sparkles,
  Target,
  TrendingDown,
  TrendingUp,
} from 'lucide-vue-next'

/*
 * One ranked list of suggestions, shared by the student Analytics page and the
 * teacher Reports page. Both sides are fed by a planner that emits the same
 * shape (StudyActionPlanner / TeacherActionPlanner), so the two audiences read
 * the same kind of card rather than two dialects of one idea.
 */
const props = defineProps({
  actions: {
    type: Array,
    default: () => [],
  },
  leadKicker: {
    type: String,
    default: 'Do this first',
  },
})

/*
 * Which cards have their reasoning open. The lead one starts expanded because
 * it is the suggestion being made; the rest stay closed so the panel reads as
 * a short ranked list rather than several essays stacked on each other.
 */
const openActions = ref(new Set([0]))

function isActionOpen(index) {
  return openActions.value.has(index)
}

function toggleAction(index) {
  const next = new Set(openActions.value)
  next.has(index) ? next.delete(index) : next.add(index)
  openActions.value = next
}

function kickerFor(index, action) {
  return index === 0 ? props.leadKicker : `Then #${action.rank ?? index + 1}`
}

/*
 * Icon per tone. urgent is something actively blocked, primary the main
 * finding, opportunity the cheapest win available, info a note the reader
 * cannot act on inside the app right now.
 */
const TONE_ICONS = {
  urgent: TrendingDown,
  primary: Target,
  opportunity: Sparkles,
  info: Info,
}

function actionStyle(tone) {
  return { icon: TONE_ICONS[tone] ?? Info }
}

const TREND_STYLES = {
  improving: { icon: TrendingUp, label: 'Trending up' },
  slipping: { icon: TrendingDown, label: 'Slipping' },
  steady: { icon: Minus, label: 'Holding steady' },
}

function trendStyle(trend) {
  return TREND_STYLES[trend] ?? { icon: Minus, label: 'Too early to call a trend' }
}
</script>

<style scoped>
/* ---- Action list ---- */

.action-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 10px;
}

.action-card {
  position: relative;
  padding: 14px 16px 15px 18px;
  border-radius: 13px;
  background: #fff;
  border: 1px solid rgba(13, 21, 71, 0.1);
  box-shadow: 0 2px 7px rgba(13, 21, 71, 0.05);
  overflow: hidden;
}

/* The accent stripe carries the whole tone signal at a glance -- red for
   ground being lost, navy for the main weakness, gold for the cheapest win,
   grey for nothing-to-do-yet. */
.action-card::before {
  content: '';
  position: absolute;
  inset: 0 auto 0 0;
  width: 3px;
}

.action-card.is-urgent::before { background: linear-gradient(180deg, #d4574f, var(--lnu-danger)); }
.action-card.is-primary::before { background: linear-gradient(180deg, #3a49c4, var(--lnu-navy)); }
.action-card.is-opportunity::before { background: linear-gradient(180deg, #e0b055, #b07d1a); }
.action-card.is-info::before { background: linear-gradient(180deg, #9aa4bd, #6b7793); }

/* Everything after the first is a queued item, not a call to action, so it
   sits back: flatter surface, no shadow, tighter box. */
.action-card.is-compact {
  padding: 12px 14px 13px 16px;
  background: rgba(255, 255, 255, 0.72);
  box-shadow: none;
}

.action-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}

.action-chip {
  flex-shrink: 0;
  width: 26px;
  height: 26px;
  border-radius: 9px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(13, 21, 71, 0.06);
  color: #3a4568;
}

.action-card.is-urgent .action-chip { background: rgba(198, 40, 40, 0.11); color: var(--lnu-danger); }
.action-card.is-primary .action-chip { background: rgba(26, 35, 126, 0.1); color: var(--lnu-navy); }
.action-card.is-opportunity .action-chip { background: rgba(176, 125, 26, 0.13); color: #b07d1a; }
.action-card.is-info .action-chip { background: rgba(107, 119, 147, 0.12); color: #6b7793; }

.action-card.is-compact .action-chip {
  width: 22px;
  height: 22px;
  border-radius: 7px;
}

.action-kicker {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: #5c6b8a;
}

.action-effort {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 600;
  color: #6b7793;
  white-space: nowrap;
}

/* The one-line summary. Carries the whole suggestion on its own, so a student
   skimming never has to open the breakdown to know what is being asked. */
.action-lead {
  margin: 0;
  font-size: 14.5px;
  font-weight: 700;
  line-height: 1.5;
  color: #12204a;
  overflow-wrap: break-word;
}

.action-card.is-compact .action-lead {
  font-size: 13.5px;
}

/* ---- Inline tracing meter ---- */

.action-meter {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
}

.action-meter-track {
  flex: 1 1 auto;
  min-width: 0;
  height: 5px;
  border-radius: 999px;
  background: rgba(13, 21, 71, 0.09);
  overflow: hidden;
}

.action-meter-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  transition: width 0.3s ease;
}

.action-meter-fill.is-struggling { background: linear-gradient(90deg, #d4574f, var(--lnu-danger)); }
.action-meter-fill.is-developing { background: linear-gradient(90deg, #e0b055, #b07d1a); }
.action-meter-fill.is-mastered { background: linear-gradient(90deg, #4fa86f, #1b6b41); }

.action-meter-value {
  flex-shrink: 0;
  font-size: 12.5px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.action-meter-value.is-struggling { color: var(--lnu-danger); }
.action-meter-value.is-developing { color: #b07d1a; }
.action-meter-value.is-mastered { color: #1b6b41; }

.action-meter-note {
  flex-shrink: 0;
  font-size: 10.5px;
  font-weight: 600;
  color: #8592ad;
  white-space: nowrap;
}

/* ---- Breakdown ---- */

.action-breakdown {
  margin-top: 11px;
  padding-top: 11px;
  border-top: 1px dashed rgba(13, 21, 71, 0.11);
}

.action-block + .action-block {
  margin-top: 10px;
}

.action-block-label {
  display: block;
  margin-bottom: 4px;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #8592ad;
}

.action-points {
  margin: 0;
  padding-left: 15px;
  display: grid;
  gap: 3px;
}

.action-points li,
.action-block-text {
  margin: 0;
  font-size: 12.5px;
  font-weight: 500;
  line-height: 1.6;
  color: #40506f;
  overflow-wrap: break-word;
}

.action-points li::marker {
  color: #b3bccd;
}

/* ---- Footer ---- */

.action-foot {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
  flex-wrap: wrap;
}

.action-cta {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 13px;
  border-radius: var(--control-radius, 9px);
  border: 1px solid rgba(26, 35, 126, 0.22);
  background: rgba(26, 35, 126, 0.05);
  color: var(--lnu-navy);
  font-size: 12px;
  font-weight: 700;
  text-decoration: none;
  transition: background 0.2s ease, box-shadow 0.2s ease;
}

/* Only the first action gets the solid button -- if every row shouted, the
   ranking the planner just worked out would be invisible. */
.action-cta.is-lead {
  padding: 9px 15px;
  border-color: transparent;
  background: var(--lnu-navy);
  color: #fff;
  font-size: 12.5px;
}

.action-cta:hover { background: rgba(26, 35, 126, 0.11); }

.action-cta.is-lead:hover {
  background: var(--lnu-navy-light, #2a35a0);
  box-shadow: 0 6px 14px rgba(26, 35, 126, 0.22);
}

.action-cta:focus-visible,
.action-toggle:focus-visible {
  outline: 2px solid var(--lnu-navy);
  outline-offset: 2px;
}

.action-toggle {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 2px;
  border: none;
  background: none;
  cursor: pointer;
  font-size: 11.5px;
  font-weight: 600;
  color: #6b7793;
  transition: color 0.2s ease;
}

.action-toggle:hover {
  color: var(--lnu-navy);
}

.action-toggle-icon {
  transition: transform 0.2s ease;
}

.action-toggle-icon.is-open {
  transform: rotate(180deg);
}

@media (max-width: 520px) {
  .action-meter-note { display: none; }
  .action-toggle { margin-left: 0; }
}
</style>
