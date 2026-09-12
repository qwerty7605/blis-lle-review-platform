<template>
  <section class="dashboard-view reports-view">
    <div v-if="reportError" class="feedback danger" role="alert">
      <AlertCircle :size="15" />
      <span>{{ reportError }}</span>
    </div>
    <div v-if="reportExportMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ reportExportMessage }}</span>
    </div>
    <div v-if="reportExportError" class="feedback danger" role="alert">
      <AlertCircle :size="15" />
      <span>{{ reportExportError }}</span>
    </div>

    <!-- Headline numbers -->
    <section aria-labelledby="rp-overview-heading">
      <header class="section-head">
        <h2 id="rp-overview-heading" class="section-title">Class Overview</h2>
        <button type="button" class="ghost-btn" :disabled="reportLoading" @click="loadReports">
          <RefreshCw :size="13" :class="{ 'spin-soft': reportLoading }" />
          Refresh
        </button>
      </header>

      <div class="stats-grid">
        <article v-for="card in reportMetricCards" :key="card.label" class="stat-card">
          <div class="stat-icon" :class="`tone-${card.tone}`">
            <component :is="card.icon" :size="17" />
          </div>
          <div>
            <p class="stat-label">{{ card.label }}</p>
            <p class="stat-value">{{ card.value }}</p>
            <p class="stat-trend" :class="{ positive: card.positive, negative: !card.positive }">
              {{ card.trend }}
            </p>
          </div>
        </article>
      </div>
    </section>

    <!-- Distribution + trend -->
    <section aria-labelledby="rp-shape-heading">
      <header class="section-head">
        <h2 id="rp-shape-heading" class="section-title">Score Shape &amp; Direction</h2>
        <span class="section-count">{{ analytics.graded_attempts }} graded result(s)</span>
      </header>

      <div class="insight-grid">
        <article class="surface-card rp-panel">
          <header class="rp-panel-head">
            <div>
              <h3>Score Distribution</h3>
              <p>How the class is actually spread, not just the average.</p>
            </div>
          </header>

          <div v-if="reportLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Loading...</span>
          </div>

          <div v-else-if="scoreDistribution.length === 0" class="rp-empty">
            <BarChart3 :size="22" />
            <h4>No graded results yet</h4>
            <p>Distribution appears once students submit.</p>
          </div>

          <template v-else>
            <p class="rp-callout">
              <strong>{{ passingCount }}</strong> of <strong>{{ analytics.graded_attempts }}</strong>
              results cleared the 75% mark
              <span class="rp-callout-sub">({{ passingShare }}%)</span>
            </p>

            <div class="rp-dist">
              <div v-for="band in scoreDistribution" :key="band.label" class="rp-dist-col">
                <span class="rp-dist-count">{{ band.count }}</span>
                <div class="rp-dist-track">
                  <div
                    class="rp-dist-bar"
                    :class="band.is_passing ? 'is-pass' : 'is-fail'"
                    :style="{ height: `${distributionHeight(band)}%` }"
                  />
                </div>
                <span class="rp-dist-label">{{ band.label }}</span>
                <span class="rp-dist-percent">{{ band.percent }}%</span>
              </div>
            </div>

            <div class="rp-legend">
              <span><i class="rp-dot is-fail" />Below passing</span>
              <span><i class="rp-dot is-pass" />Passing</span>
            </div>
          </template>
        </article>

        <article class="surface-card rp-panel">
          <header class="rp-panel-head">
            <div>
              <h3>Performance Trend</h3>
              <p>Average score per session, oldest to newest.</p>
            </div>
            <span v-if="trendDelta !== null" class="pill" :class="trendDelta >= 0 ? 'success' : 'danger'">
              {{ trendDelta >= 0 ? '+' : '' }}{{ trendDelta }} pts
            </span>
          </header>

          <div v-if="reportLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Loading...</span>
          </div>

          <div v-else-if="trendPoints.length === 0" class="rp-empty">
            <TrendingUp :size="22" />
            <h4>Not enough sessions yet</h4>
            <p>Two or more graded sessions will plot a trend.</p>
          </div>

          <template v-else>
            <p class="rp-callout">{{ trendSummary }}</p>
            <ScoreTrendChart :points="trendPoints" :threshold="75" variant="compact" />
          </template>
        </article>
      </div>
    </section>

    <!-- Actionable: who and what needs attention -->
    <section aria-labelledby="rp-attention-heading">
      <header class="section-head">
        <h2 id="rp-attention-heading" class="section-title">Needs Attention</h2>
      </header>

      <div class="insight-grid">
        <article class="surface-card rp-panel">
          <header class="rp-panel-head">
            <div>
              <h3>Students Below Passing</h3>
              <p>Ranked by average across your sessions, lowest first.</p>
            </div>
            <span class="pill" :class="studentsNeedingAttention.length > 0 ? 'danger' : 'success'">
              {{ studentsNeedingAttention.length }}
            </span>
          </header>

          <div v-if="reportLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Loading...</span>
          </div>

          <div v-else-if="studentsNeedingAttention.length === 0" class="rp-empty is-good">
            <ShieldCheck :size="22" />
            <h4>Everyone is at or above 75%</h4>
            <p>No student in your sessions is averaging below the passing mark.</p>
          </div>

          <ol v-else class="rp-student-list">
            <li v-for="(student, index) in studentsNeedingAttention" :key="student.id" class="rp-student">
              <span class="rp-student-rank" aria-hidden="true">{{ index + 1 }}</span>
              <div class="rp-student-copy">
                <strong>{{ student.name }}</strong>
                <p>
                  {{ student.student_id || 'No student ID' }}
                  · {{ student.attempts_submitted }} graded
                  <template v-if="student.weakest_subject"> · weakest: {{ student.weakest_subject }}</template>
                </p>
              </div>
              <strong class="rp-student-score">{{ student.average_score_percent }}%</strong>
            </li>
          </ol>

          <div v-if="topPerformers.length > 0" class="rp-top">
            <span class="rp-top-label">Top performers</span>
            <div class="rp-top-row">
              <span v-for="student in topPerformers" :key="student.id" class="rp-top-chip">
                {{ student.name }}<em>{{ student.average_score_percent }}%</em>
              </span>
            </div>
          </div>
        </article>

        <article class="surface-card rp-panel">
          <header class="rp-panel-head">
            <div>
              <h3>Topic Accuracy</h3>
              <p>Per-question accuracy across every graded answer.</p>
            </div>
            <span class="section-count">{{ topicPerformance.length }} topic(s)</span>
          </header>

          <div v-if="reportLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Loading...</span>
          </div>

          <div v-else-if="topicPerformance.length === 0" class="rp-empty">
            <BookOpen :size="22" />
            <h4>No mapped topics yet</h4>
            <p>Topic accuracy appears once graded answers come from mapped questions.</p>
          </div>

          <template v-else>
            <div class="rp-topic-group">
              <span class="rp-topic-label is-weak">Reteach first</span>
              <div v-for="topic in weakestTopics" :key="`weak-${topic.topic_id}`" class="rp-topic">
                <div class="rp-topic-copy">
                  <strong>{{ topic.label }}</strong>
                  <p>{{ topic.subject }} · {{ topic.correct_count }}/{{ topic.attempted_count }} correct</p>
                </div>
                <div class="rp-topic-meter">
                  <div class="rp-topic-track">
                    <div class="rp-topic-fill is-weak" :style="{ width: `${clampPercent(topic.score)}%` }" />
                  </div>
                  <strong>{{ Math.round(topic.score) }}%</strong>
                </div>
              </div>
            </div>

            <div v-if="strongestTopics.length > 0" class="rp-topic-group">
              <span class="rp-topic-label is-strong">Well understood</span>
              <div v-for="topic in strongestTopics" :key="`strong-${topic.topic_id}`" class="rp-topic">
                <div class="rp-topic-copy">
                  <strong>{{ topic.label }}</strong>
                  <p>{{ topic.subject }} · {{ topic.correct_count }}/{{ topic.attempted_count }} correct</p>
                </div>
                <div class="rp-topic-meter">
                  <div class="rp-topic-track">
                    <div class="rp-topic-fill is-strong" :style="{ width: `${clampPercent(topic.score)}%` }" />
                  </div>
                  <strong>{{ Math.round(topic.score) }}%</strong>
                </div>
              </div>
            </div>
          </template>
        </article>
      </div>
    </section>

    <!-- Breakdowns -->
    <section aria-labelledby="rp-breakdown-heading">
      <header class="section-head">
        <h2 id="rp-breakdown-heading" class="section-title">Breakdowns</h2>
      </header>

      <div class="insight-grid">
        <article class="surface-card rp-panel">
          <header class="rp-panel-head">
            <div>
              <h3>Subject Performance</h3>
              <p>Average exam score by subject.</p>
            </div>
            <span class="section-count">{{ reportSubjectPerformance.length }}</span>
          </header>

          <div v-if="reportLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Loading subject trends...</span>
          </div>

          <div v-else-if="reportSubjectPerformance.length === 0" class="rp-empty">
            <BookOpen :size="22" />
            <h4>No subject trends yet</h4>
            <p>Submitted attempts populate the breakdown by subject.</p>
          </div>

          <div v-else class="rp-subject-list">
            <div v-for="subject in reportSubjectPerformance" :key="subject.label" class="rp-subject">
              <div class="rp-subject-head">
                <span>{{ subject.label }}</span>
                <strong :class="Number(subject.score ?? 0) >= 75 ? 'rp-ok' : 'rp-bad'">
                  {{ formatPercent(subject.score) }}
                </strong>
              </div>
              <div class="rp-topic-track">
                <div
                  class="rp-topic-fill"
                  :class="Number(subject.score ?? 0) >= 75 ? 'is-strong' : 'is-weak'"
                  :style="{ width: `${clampPercent(subject.score)}%` }"
                />
              </div>
            </div>
          </div>
        </article>

        <article class="surface-card rp-panel">
          <header class="rp-panel-head">
            <div>
              <h3>Room Comparison</h3>
              <p>Which section is ahead, and which is falling behind.</p>
            </div>
            <span class="section-count">{{ roomComparison.length }}</span>
          </header>

          <div v-if="reportLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Loading...</span>
          </div>

          <div v-else-if="roomComparison.length === 0" class="rp-empty">
            <DoorOpen :size="22" />
            <h4>No room results yet</h4>
            <p>Comparison appears once more than one room has graded results.</p>
          </div>

          <div v-else class="rp-room-list">
            <div v-for="room in roomComparison" :key="room.room_id" class="rp-room">
              <div class="rp-room-copy">
                <strong>{{ room.room_name }}</strong>
                <p>
                  <span v-if="room.room_code">{{ room.room_code }} · </span>
                  {{ room.students_graded }} student(s) · {{ room.graded_attempts }} graded
                </p>
              </div>
              <div class="rp-room-stats">
                <span class="rp-room-stat">
                  <em>Avg</em>
                  <strong :class="Number(room.average_score_percent) >= 75 ? 'rp-ok' : 'rp-bad'">
                    {{ room.average_score_percent }}%
                  </strong>
                </span>
                <span class="rp-room-stat">
                  <em>Pass</em>
                  <strong>{{ room.pass_rate_percent }}%</strong>
                </span>
              </div>
            </div>
          </div>
        </article>
      </div>
    </section>

    <!-- Session detail -->
    <section aria-labelledby="rp-session-heading">
      <header class="section-head">
        <h2 id="rp-session-heading" class="section-title">Session Performance</h2>
        <span class="section-count">{{ reportSessionPerformance.length }}</span>
      </header>

      <article class="surface-card rp-panel">
        <div v-if="reportLoading" class="rp-inline-loading">
          <RefreshCw :size="15" class="spin-soft" />
          <span>Loading report data...</span>
        </div>

        <div v-else-if="reportSessionPerformance.length === 0" class="rp-empty">
          <BarChart3 :size="22" />
          <h4>No session data yet</h4>
          <p>Assign exams to rooms and wait for student activity to populate this view.</p>
        </div>

        <div v-else class="rp-session-list">
          <article v-for="session in reportSessionPerformance" :key="`${session.exam_id}-${session.room_id}`" class="rp-session">
            <div class="rp-session-copy">
              <strong>{{ session.exam_title }}</strong>
              <p>{{ session.room_name }}{{ session.room_code ? ` (${session.room_code})` : '' }}</p>
            </div>

            <div class="rp-session-metrics">
              <span class="pill neutral">{{ session.students_submitted }}/{{ session.students_total }} submitted</span>
              <span class="pill navy">{{ formatPercent(session.completion_rate_percent) }} done</span>
              <strong :class="Number(session.average_score_percent ?? 0) >= 75 ? 'rp-ok' : 'rp-bad'">
                {{ formatPercent(session.average_score_percent) }}
              </strong>
            </div>
          </article>
        </div>
      </article>
    </section>

    <!-- Item analytics -->
    <section aria-labelledby="rp-item-heading">
      <header class="section-head">
        <h2 id="rp-item-heading" class="section-title">Item-Level Analytics</h2>
        <span class="section-count">Question difficulty tracking</span>
      </header>

      <article class="surface-card rp-panel">
        <label class="field-stack rp-item-picker">
          <span class="field-label">Target exam</span>
          <select v-model="itemAnalyticsExamId" class="text-input" :disabled="reportTargetsLoading">
            <option value="">Select an exam to analyze</option>
            <option v-for="exam in reportExportExams" :key="exam.id" :value="String(exam.id)">
              {{ exam.title }}
            </option>
          </select>
        </label>

        <div v-if="itemAnalyticsLoading" class="rp-inline-loading">
          <RefreshCw :size="15" class="spin-soft" />
          <span>Calculating item difficulties...</span>
        </div>

        <div v-else-if="itemAnalyticsError" class="feedback danger" role="alert">
          <AlertCircle :size="15" />
          <span>{{ itemAnalyticsError }}</span>
        </div>

        <div v-else-if="itemAnalyticsExamId && itemAnalyticsData.length === 0" class="rp-empty">
          <FileText :size="22" />
          <h4>No attempts recorded</h4>
          <p>Wait for students to complete this exam to analyze item failure rates.</p>
        </div>

        <div v-else-if="itemAnalyticsData.length > 0" class="rp-item-list">
          <div v-for="item in itemAnalyticsData" :key="item.question_id" class="rp-item">
            <div class="rp-item-copy">
              <strong>{{ item.question_text }}</strong>
              <p>{{ item.total_attempts }} attempt(s) recorded</p>
            </div>
            <strong :class="item.failure_rate_percent >= 50 ? 'rp-bad' : 'rp-ok'">
              {{ item.failure_rate_percent }}% failed
            </strong>
          </div>
        </div>

        <p v-else class="rp-hint">Pick an exam above to see which questions students miss most.</p>
      </article>
    </section>

    <!-- Activity -->
    <section aria-labelledby="rp-activity-heading">
      <header class="section-head">
        <h2 id="rp-activity-heading" class="section-title">Recent Activity</h2>
        <span class="section-count">{{ reportActivity.length }}</span>
      </header>

      <article class="surface-card rp-panel">
        <div v-if="reportLoading" class="rp-inline-loading">
          <RefreshCw :size="15" class="spin-soft" />
          <span>Loading report data...</span>
        </div>

        <div v-else-if="reportActivity.length === 0" class="rp-empty">
          <ClipboardList :size="22" />
          <h4>No activity yet</h4>
          <p>Recent operational logs will appear here.</p>
        </div>

        <div v-else class="rp-activity-list">
          <div v-for="activity in reportActivity" :key="activity.id" class="rp-activity">
            <span class="rp-activity-dot" aria-hidden="true" />
            <div class="rp-activity-copy">
              <strong>{{ activity.description || activity.action }}</strong>
              <p>{{ formatDateTime(activity.created_at) }} · {{ activity.actor?.name || 'System' }}</p>
            </div>
            <span class="pill neutral">{{ activity.action }}</span>
          </div>
        </div>
      </article>
    </section>

    <!-- Export center (unchanged behaviour) -->
    <details class="surface-card collapsible-card report-export-card">
      <summary class="collapsible-summary">
        <div class="surface-head-copy">
          <span class="section-kicker">Export Center</span>
          <h3>Generate Session Reports</h3>
          <p>Download or email result files only when you need them, without crowding the main report view.</p>
        </div>

        <div class="collapsible-summary-meta">
          <span class="pill" :class="canExportSessionReports ? 'success' : 'neutral'">
            {{ canExportSessionReports ? 'Ready' : 'Select exam and room' }}
          </span>
          <ChevronDown :size="18" class="collapsible-icon" />
        </div>
      </summary>

      <div class="collapsible-content">
        <div class="surface-head compact">
          <h3>Export Setup</h3>
          <button type="button" class="ghost-btn" :disabled="reportTargetsLoading" @click="loadReportExportTargets(true)">
            <RefreshCw :size="14" :class="{ 'spin-soft': reportTargetsLoading }" />
            Refresh
          </button>
        </div>

        <div v-if="reportTargetsLoading" class="rp-inline-loading">
          <RefreshCw :size="15" class="spin-soft" />
          <span>Loading export targets...</span>
        </div>

        <template v-else>
          <div class="room-form-grid">
            <label class="field-stack">
              <span class="field-label">Exam</span>
              <select v-model="reportExportForm.exam_id" class="text-input">
                <option value="">Select exam</option>
                <option v-for="exam in reportExportExams" :key="exam.id" :value="String(exam.id)">
                  {{ exam.title }}
                </option>
              </select>
            </label>

            <label class="field-stack">
              <span class="field-label">Room</span>
              <select v-model="reportExportForm.room_id" class="text-input" :disabled="!reportExportForm.exam_id">
                <option value="">Select room</option>
                <option v-for="room in reportExportRoomOptions" :key="room.id" :value="String(room.id)">
                  {{ room.name }}{{ room.code ? ` (${room.code})` : '' }}
                </option>
              </select>
            </label>

            <label class="field-stack">
              <span class="field-label">Student (optional)</span>
              <select
                v-model="reportExportForm.student_id"
                class="text-input"
                :disabled="!reportExportForm.room_id || reportStudentsLoading || reportExportStudents.length === 0"
              >
                <option value="">Select student</option>
                <option v-for="student in reportExportStudents" :key="student.id" :value="String(student.id)">
                  {{ student.name }} ({{ student.student_id || 'No ID' }})
                </option>
              </select>
            </label>

            <div class="field-stack">
              <span class="field-label">Email delivery</span>
              <label class="check-item">
                <input v-model="reportExportForm.verified_only" type="checkbox" />
                <span>Send only to verified student emails</span>
              </label>
            </div>
          </div>

          <div class="overview-pill-row report-selection-row" v-if="selectedReportExam || selectedReportRoom || selectedReportStudent">
            <span v-if="selectedReportExam" class="pill navy">{{ selectedReportExam.title }}</span>
            <span v-if="selectedReportRoom" class="pill neutral">
              {{ selectedReportRoom.name }}{{ selectedReportRoom.code ? ` (${selectedReportRoom.code})` : '' }}
            </span>
            <span v-if="selectedReportStudent" class="pill success">{{ selectedReportStudent.name }}</span>
          </div>

          <div class="report-action-sections">
            <section class="action-section">
              <header class="action-section-head">
                <h4>Downloads</h4>
                <p>Use these when you need printable or downloadable files for the panel or class records.</p>
              </header>

              <div class="report-action-grid">
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('xlsx')"
                >
                  <RefreshCw v-if="reportExportingKey === 'xlsx'" :size="14" class="spin-soft" />
                  <span>Results XLSX</span>
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('csv')"
                >
                  <RefreshCw v-if="reportExportingKey === 'csv'" :size="14" class="spin-soft" />
                  <span>Results CSV</span>
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('summary_pdf')"
                >
                  <RefreshCw v-if="reportExportingKey === 'summary_pdf'" :size="14" class="spin-soft" />
                  <span>Summary PDF</span>
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('answer_key_pdf')"
                >
                  <RefreshCw v-if="reportExportingKey === 'answer_key_pdf'" :size="14" class="spin-soft" />
                  <span>Answer Key PDF</span>
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSingleStudentReport || reportExportingKey !== ''"
                  @click="exportReport('student_pdf')"
                >
                  <RefreshCw v-if="reportExportingKey === 'student_pdf'" :size="14" class="spin-soft" />
                  <span>Student PDF</span>
                </button>
                <button
                  type="button"
                  class="primary-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('student_zip')"
                >
                  <RefreshCw v-if="reportExportingKey === 'student_zip'" :size="14" class="spin-soft" />
                  <span>All PDFs ZIP</span>
                </button>
              </div>
            </section>

            <section class="action-section">
              <header class="action-section-head">
                <h4>Email Delivery</h4>
                <p>Use these only when you are ready to send finalized student reports.</p>
              </header>

              <div class="report-action-grid">
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSingleStudentReport || reportExportingKey !== ''"
                  @click="sendReportEmail('student_email')"
                >
                  <RefreshCw v-if="reportExportingKey === 'student_email'" :size="14" class="spin-soft" />
                  <span>Email Student PDF</span>
                </button>
                <button
                  type="button"
                  class="primary-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="sendReportEmail('student_email_bulk')"
                >
                  <RefreshCw v-if="reportExportingKey === 'student_email_bulk'" :size="14" class="spin-soft" />
                  <span>Email All Reports</span>
                </button>
              </div>
            </section>
          </div>
        </template>
      </div>
    </details>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import {
  AlertCircle,
  BarChart3,
  BookOpen,
  CheckCircle2,
  ChevronDown,
  ClipboardList,
  DoorOpen,
  FileText,
  RefreshCw,
  ShieldCheck,
  TrendingUp,
} from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useReportsModule } from '../composables/useReportsModule'
import ScoreTrendChart from '@/components/charts/ScoreTrendChart.vue'

const {
  reportLoading,
  reportError,
  reportActivity,
  reportSessionPerformance,
  reportSubjectPerformance,
  reportAnalytics,
  reportTargetsLoading,
  reportStudentsLoading,
  reportExportingKey,
  reportExportError,
  reportExportMessage,
  reportExportExams,
  reportExportStudents,
  reportExportForm,
  reportExportRoomOptions,
  canExportSessionReports,
  canExportSingleStudentReport,
  reportMetricCards,
  formatPercent,
  loadReports,
  loadReportExportTargets,
  exportReport,
  sendReportEmail,
  itemAnalyticsData,
  itemAnalyticsLoading,
  itemAnalyticsError,
  itemAnalyticsExamId,
} = useReportsModule()

const PASSING_SCORE = 75

const analytics = computed(() => ({
  score_distribution: [],
  trend: [],
  topic_performance: [],
  students_needing_attention: [],
  top_performers: [],
  room_comparison: [],
  graded_attempts: 0,
  students_graded: 0,
  ...(reportAnalytics.value ?? {}),
}))

const scoreDistribution = computed(() => analytics.value.score_distribution)
const topicPerformance = computed(() => analytics.value.topic_performance)
const studentsNeedingAttention = computed(() => analytics.value.students_needing_attention)
const topPerformers = computed(() => analytics.value.top_performers)
const roomComparison = computed(() => analytics.value.room_comparison)

const passingCount = computed(() => (
  scoreDistribution.value
    .filter((band) => band.is_passing)
    .reduce((total, band) => total + Number(band.count ?? 0), 0)
))

const passingShare = computed(() => {
  const total = Number(analytics.value.graded_attempts ?? 0)
  if (total === 0) return 0

  return Math.round((passingCount.value / total) * 100)
})

// Bars are scaled against the tallest band, not against the total, so a
// distribution where one band holds most results still shows the shape of
// the smaller bands instead of flattening them to slivers.
function distributionHeight(band) {
  const tallest = scoreDistribution.value.reduce((max, row) => Math.max(max, Number(row.count ?? 0)), 0)
  if (tallest === 0) return 0

  return Math.max(2, Math.round((Number(band.count ?? 0) / tallest) * 100))
}

const trendPoints = computed(() => (
  analytics.value.trend.map((row) => ({
    id: `${row.exam_id}-${row.room_id}`,
    label: row.exam_title,
    value: Number(row.average_score_percent ?? 0),
    sublabel: row.label,
    tone: Number(row.average_score_percent ?? 0) >= PASSING_SCORE ? 'success' : 'danger',
  }))
))

/** First-to-last movement across the plotted sessions -- the number a teacher actually wants from a trend. */
const trendDelta = computed(() => {
  const points = analytics.value.trend
  if (points.length < 2) return null

  const first = Number(points[0].average_score_percent ?? 0)
  const last = Number(points[points.length - 1].average_score_percent ?? 0)

  return Math.round((last - first) * 10) / 10
})

const trendSummary = computed(() => {
  const points = analytics.value.trend
  if (points.length === 0) return ''

  const last = points[points.length - 1]
  const lastScore = Math.round(Number(last.average_score_percent ?? 0))

  if (trendDelta.value === null) {
    return `Latest session "${last.exam_title}" averaged ${lastScore}%.`
  }

  if (trendDelta.value > 0) {
    return `Up ${trendDelta.value} points since the first session — latest averaged ${lastScore}%.`
  }

  if (trendDelta.value < 0) {
    return `Down ${Math.abs(trendDelta.value)} points since the first session — latest averaged ${lastScore}%.`
  }

  return `Flat across ${points.length} sessions — latest averaged ${lastScore}%.`
})

const weakestTopics = computed(() => topicPerformance.value.slice(0, 5))

/* Only genuinely strong topics qualify, and never one already listed as weak
   -- with a short topic list the "best" entry can still be a failing one. */
const strongestTopics = computed(() => {
  const weakIds = new Set(weakestTopics.value.map((topic) => topic.topic_id))

  return [...topicPerformance.value]
    .filter((topic) => !weakIds.has(topic.topic_id) && Number(topic.score) >= PASSING_SCORE)
    .sort((left, right) => Number(right.score) - Number(left.score))
    .slice(0, 3)
})

function clampPercent(value) {
  return Math.max(0, Math.min(100, Number(value ?? 0)))
}

const selectedReportExam = computed(() => (
  reportExportExams.value.find((exam) => String(exam.id) === String(reportExportForm.exam_id)) ?? null
))

const selectedReportRoom = computed(() => (
  reportExportRoomOptions.value.find((room) => String(room.id) === String(reportExportForm.room_id)) ?? null
))

const selectedReportStudent = computed(() => (
  reportExportStudents.value.find((student) => String(student.id) === String(reportExportForm.student_id)) ?? null
))
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.reports-view {
  gap: 20px;
}

/* ---- Panel shell ---- */
.rp-panel {
  display: grid;
  gap: 12px;
  align-content: start;
}

.rp-panel-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.rp-panel-head h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 800;
  color: var(--ink);
}

.rp-panel-head p {
  margin: 2px 0 0;
  font-size: 11.5px;
  line-height: 1.45;
  color: var(--ink-soft);
}

.rp-callout {
  margin: 0;
  padding: 9px 11px;
  border-radius: 10px;
  background: var(--surface-muted);
  border: 1px solid var(--border-soft);
  font-size: 12px;
  line-height: 1.5;
  color: #4f5d78;
}

.rp-callout strong {
  color: var(--ink);
  font-weight: 800;
}

.rp-callout-sub {
  color: var(--ink-soft);
}

.rp-hint {
  margin: 0;
  font-size: 12px;
  color: var(--ink-soft);
}

.rp-inline-loading {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 14px 2px;
  font-size: 12.5px;
  color: var(--ink-soft);
}

.rp-empty {
  display: grid;
  justify-items: center;
  align-content: center;
  gap: 5px;
  min-height: 150px;
  padding: 18px;
  text-align: center;
  border: 1px dashed var(--border-soft);
  border-radius: 12px;
  background: var(--surface-muted);
  color: var(--ink-soft);
}

.rp-empty.is-good {
  border-style: solid;
  border-color: rgba(47, 138, 79, 0.22);
  background: #f1faf4;
  color: #3c6a51;
}

.rp-empty h4 {
  margin: 2px 0 0;
  font-size: 13px;
  font-weight: 800;
  color: var(--ink);
}

.rp-empty p {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
  max-width: 34ch;
}

/* ---- Distribution histogram ---- */
.rp-dist {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  align-items: end;
}

.rp-dist-col {
  display: grid;
  justify-items: center;
  gap: 4px;
}

.rp-dist-count {
  font-size: 13px;
  font-weight: 800;
  color: var(--ink);
}

.rp-dist-track {
  width: 100%;
  height: 108px;
  display: flex;
  align-items: flex-end;
  border-radius: 8px;
  background: var(--surface-muted);
  overflow: hidden;
}

.rp-dist-bar {
  width: 100%;
  border-radius: 8px 8px 0 0;
  transition: height 0.35s ease;
}

.rp-dist-bar.is-fail { background: linear-gradient(180deg, #e2564f, #c9433d); }
.rp-dist-bar.is-pass { background: linear-gradient(180deg, #3f9c64, #2f8a4f); }

.rp-dist-label {
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: var(--ink-muted);
}

.rp-dist-percent {
  font-size: 10.5px;
  color: var(--ink-soft);
}

.rp-legend {
  display: flex;
  gap: 14px;
  font-size: 11px;
  color: var(--ink-soft);
}

.rp-legend span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.rp-dot {
  width: 8px;
  height: 8px;
  border-radius: 3px;
}

.rp-dot.is-fail { background: #c9433d; }
.rp-dot.is-pass { background: #2f8a4f; }

/* ---- Student watchlist ---- */
.rp-student-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  border-radius: 12px;
  border: 1px solid var(--border-soft);
  overflow: hidden;
}

.rp-student {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  background: var(--surface);
}

.rp-student + .rp-student {
  border-top: 1px solid var(--border-soft);
}

.rp-student-rank {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(198, 40, 40, 0.1);
  color: #a8302f;
  font-size: 10.5px;
  font-weight: 800;
}

.rp-student-copy {
  flex: 1;
  min-width: 0;
}

.rp-student-copy strong {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink);
}

.rp-student-copy p {
  margin: 2px 0 0;
  font-size: 11px;
  color: var(--ink-soft);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rp-student-score {
  flex-shrink: 0;
  font-size: 14px;
  font-weight: 800;
  color: #a8302f;
}

.rp-top {
  display: grid;
  gap: 6px;
  padding-top: 2px;
}

.rp-top-label {
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.rp-top-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.rp-top-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(47, 138, 79, 0.12);
  color: #226b3f;
  font-size: 11.5px;
  font-weight: 700;
}

.rp-top-chip em {
  font-style: normal;
  font-weight: 800;
}

/* ---- Topics ---- */
.rp-topic-group {
  display: grid;
  gap: 7px;
}

.rp-topic-label {
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.rp-topic-label.is-weak { color: #a8302f; }
.rp-topic-label.is-strong { color: #226b3f; }

.rp-topic {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 10px;
  border-radius: 10px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
}

.rp-topic-copy {
  flex: 1;
  min-width: 0;
}

.rp-topic-copy strong {
  display: block;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink);
}

.rp-topic-copy p {
  margin: 2px 0 0;
  font-size: 10.5px;
  color: var(--ink-soft);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rp-topic-meter {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  width: 118px;
}

.rp-topic-meter strong {
  min-width: 34px;
  text-align: right;
  font-size: 12px;
  font-weight: 800;
  color: var(--ink);
}

.rp-topic-track {
  flex: 1;
  height: 5px;
  border-radius: 999px;
  background: rgba(13, 21, 71, 0.08);
  overflow: hidden;
}

.rp-topic-fill {
  height: 100%;
  border-radius: 999px;
  background: #315cbf;
  transition: width 0.35s ease;
}

.rp-topic-fill.is-weak { background: #d9443d; }
.rp-topic-fill.is-strong { background: #2f8a4f; }

/* ---- Subjects ---- */
.rp-subject-list {
  display: grid;
  gap: 11px;
}

.rp-subject-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 5px;
  font-size: 12.5px;
  color: var(--ink);
}

.rp-subject-head strong {
  font-size: 13px;
  font-weight: 800;
}

/* ---- Rooms ---- */
.rp-room-list {
  display: grid;
  gap: 8px;
}

.rp-room {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
}

.rp-room-copy {
  flex: 1;
  min-width: 0;
}

.rp-room-copy strong {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink);
}

.rp-room-copy p {
  margin: 2px 0 0;
  font-size: 11px;
  color: var(--ink-soft);
}

.rp-room-stats {
  flex-shrink: 0;
  display: flex;
  gap: 14px;
}

.rp-room-stat {
  display: grid;
  justify-items: end;
  gap: 1px;
}

.rp-room-stat em {
  font-style: normal;
  font-size: 9px;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.rp-room-stat strong {
  font-size: 13.5px;
  font-weight: 800;
  color: var(--ink);
}

/* ---- Sessions / items / activity ---- */
.rp-session-list,
.rp-item-list,
.rp-activity-list {
  display: grid;
  gap: 8px;
}

.rp-session,
.rp-item,
.rp-activity {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid var(--border-soft);
  background: var(--surface);
}

.rp-session-copy,
.rp-item-copy,
.rp-activity-copy {
  flex: 1;
  min-width: 0;
}

.rp-session-copy strong,
.rp-item-copy strong,
.rp-activity-copy strong {
  display: block;
  font-size: 13px;
  font-weight: 700;
  line-height: 1.4;
  color: var(--ink);
}

.rp-session-copy p,
.rp-item-copy p,
.rp-activity-copy p {
  margin: 2px 0 0;
  font-size: 11px;
  color: var(--ink-soft);
}

.rp-session-metrics {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.rp-session-metrics strong,
.rp-item strong {
  font-size: 13.5px;
  font-weight: 800;
  white-space: nowrap;
}

.rp-item-picker {
  max-width: 420px;
}

.rp-activity-dot {
  flex-shrink: 0;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--lnu-info);
}

.rp-ok { color: #226b3f; }
.rp-bad { color: #a8302f; }

@media (max-width: 640px) {
  .rp-panel-head,
  .rp-session,
  .rp-room,
  .rp-item,
  .rp-topic {
    flex-direction: column;
    align-items: flex-start;
  }

  .rp-topic-meter,
  .rp-item-picker {
    width: 100%;
    max-width: none;
  }

  .rp-session-metrics {
    justify-content: flex-start;
  }
}
</style>
