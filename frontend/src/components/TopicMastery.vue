<template>
  <div class="topic-mastery">
    <p v-if="!subjects.length" class="topic-mastery-empty">{{ emptyText }}</p>

    <section
      v-for="subject in subjects"
      :key="subject.subject"
      class="tm-subject"
      :aria-label="`${subject.subject} topics`"
    >
      <header class="tm-subject-head">
        <strong class="tm-subject-name">{{ subject.subject }}</strong>
        <span class="tm-subject-score">
          {{ formatPercent(subject.score) }} correct
          <span class="tm-muted">· {{ subject.items }} {{ subject.items === 1 ? 'question' : 'questions' }}</span>
        </span>
      </header>

      <p v-if="!subject.topics.length" class="tm-muted tm-note">
        None of these questions is tagged to a topic of the Table of Specifications yet.
      </p>

      <ul v-else class="tm-topics">
        <li v-for="topic in subject.topics" :key="topic.topic_id" class="tm-topic">
          <div class="tm-row">
            <span class="tm-label">{{ topic.label }}</span>
            <span class="tm-value" :class="`is-${band(topic)}`">{{ masteryText(topic) }}</span>
          </div>
          <div class="tm-track" role="img" :aria-label="`${topic.label}: estimated grasp ${masteryText(topic)}`">
            <span class="tm-fill" :class="`is-${band(topic)}`" :style="{ width: `${fillWidth(topic)}%` }" />
          </div>
          <p class="tm-foot">
            {{ trendText(topic) }}
            <span aria-hidden="true">·</span>
            {{ topic.correct }} of {{ topic.items }} right
            <template v-if="exams(topic)">in {{ exams(topic) }} {{ exams(topic) === 1 ? 'exam' : 'exams' }}</template>
          </p>

          <details v-if="topic.subtopics.length" class="tm-subtopics">
            <summary>Sub-topics ({{ topic.subtopics.length }})</summary>
            <ul>
              <li v-for="subtopic in topic.subtopics" :key="subtopic.subtopic_id" class="tm-subtopic">
                <div class="tm-row">
                  <span class="tm-label">{{ subtopic.label }}</span>
                  <span class="tm-value" :class="`is-${band(subtopic)}`">{{ masteryText(subtopic) }}</span>
                </div>
                <div class="tm-track is-thin" role="img" :aria-label="`${subtopic.label}: estimated grasp ${masteryText(subtopic)}`">
                  <span class="tm-fill" :class="`is-${band(subtopic)}`" :style="{ width: `${fillWidth(subtopic)}%` }" />
                </div>
                <p class="tm-foot">
                  {{ trendText(subtopic) }}
                  <span aria-hidden="true">·</span>
                  {{ subtopic.correct }} of {{ subtopic.items }} right
                </p>
              </li>
            </ul>
          </details>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
/*
 * Topic and sub-topic mastery grouped under each subject (the `mastery` list
 * of /student/analytics, DashboardAnalyticsService::buildMasteryBySubject()).
 * A subject shows its percent correct; its TOS topics and sub-topics show the
 * knowledge-tracing estimate, which moves once per exam in the order the
 * exams were taken. "Other / Unclassified" never appears as a topic.
 */
defineProps({
  subjects: { type: Array, default: () => [] },
  emptyText: { type: String, default: 'Topic mastery appears here once you submit an exam.' },
})

const TREND_TEXT = {
  improving: 'Rising since the exam before',
  slipping: 'Falling since the exam before',
  steady: 'Holding steady',
  unclear: 'One exam so far',
}

function band(area) {
  return area.mastery?.band ?? 'developing'
}

function masteryText(area) {
  const percent = area.mastery?.mastery_percent
  return percent === undefined || percent === null ? '—' : `${Math.round(Number(percent))}%`
}

function fillWidth(area) {
  const percent = Number(area.mastery?.mastery_percent ?? 0)
  return Math.max(2, Math.min(100, percent))
}

function trendText(area) {
  return TREND_TEXT[area.mastery?.trend] ?? TREND_TEXT.unclear
}

function exams(area) {
  return Number(area.mastery?.opportunities ?? 0)
}

function formatPercent(value) {
  return value === null || value === undefined ? '—' : `${Math.round(Number(value))}%`
}
</script>

<style scoped>
.topic-mastery {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 14px;
}

.topic-mastery-empty,
.tm-note {
  margin: 0;
}

.tm-subject {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 10px;
  padding: 14px 16px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-md);
  background: var(--surface);
}

.tm-subject-head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 4px 12px;
}

.tm-subject-name {
  font-family: var(--font-display);
  font-size: 15px;
  color: var(--ink);
}

.tm-subject-score {
  font-size: 13px;
  font-weight: 600;
  color: var(--ink);
}

.tm-muted {
  color: var(--ink-muted);
  font-weight: 400;
  font-size: 13px;
}

.tm-topics,
.tm-subtopics ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 12px;
}

.tm-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.tm-label {
  min-width: 0;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink);
  overflow-wrap: anywhere;
}

.tm-subtopic .tm-label {
  font-weight: 500;
}

.tm-value {
  flex: none;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink);
}

.tm-track {
  height: 8px;
  margin-top: 6px;
  border-radius: 999px;
  background: var(--surface-muted);
  border: 1px solid var(--border-soft);
  overflow: hidden;
}

.tm-track.is-thin {
  height: 6px;
}

.tm-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
}

.tm-fill.is-struggling { background: var(--dk-bg-b7791f, #b7791f); }
.tm-fill.is-developing { background: var(--dk-bg-e0b055, #e0b055); }
.tm-fill.is-mastered { background: var(--dk-bg-lnu-success, var(--lnu-success)); }

.tm-foot {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--ink-muted);
}

.tm-subtopics {
  margin-top: 8px;
}

.tm-subtopics summary {
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-muted);
  min-height: 24px;
}

.tm-subtopics ul {
  margin-top: 8px;
  padding-left: 12px;
  border-left: 2px solid var(--border-soft);
}
</style>
